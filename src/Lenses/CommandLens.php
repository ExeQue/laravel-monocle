<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Finalizable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\TraceContext;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

/**
 * Artisan commands become captures of type command; a command invoked while
 * another capture is active (Artisan::call inside a request or a command)
 * becomes a span instead, and a row of the commands section when it finishes.
 */
final class CommandLens implements Advises, Finalizable, Lens, Presentable, Resettable, Summarizable
{
    private bool $usedLaravelStart = false;

    /** @var list<Span> open nested command spans */
    private array $spans = [];

    private Application $app;

    private ?Span $phase = null;

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'command';
    }

    public function description(): string
    {
        return 'Artisan command facts for captures of type command: name, arguments and options (both masked), exit code (a non-zero exit code is an error). Section commands has one row per Artisan::call() made while any capture (request, job, command) is active: name, class, arguments and options (masked), exit_code, failed (non-zero exit code), duration, time and origin; each is also a command span in the timeline. Long-running workers (queue:work, horizon, schedule:work) are excluded; the work they do is recorded as job captures. A command started through the Process facade while a capture records joins its trace as a child (MONOCLE_TRACE_ID and MONOCLE_PARENT_ID in the environment).';
    }

    public function advice(): string
    {
        return 'Look here for an artisan command\'s arguments, options and exit code.';
    }

    public function boot(Application $app): void
    {
        $this->app = $app;

        // The Laravel command events are bridged from Symfony's only once the
        // kernel has been asked to; Laravel does it itself outside the test
        // suite. Resolving the console kernel runs the application's own
        // callbacks for it (commands, schedule), so it is left alone on HTTP
        // requests: an Artisan::call() there resolves it anyway.
        if ($app->runningInConsole() && $app->bound(ConsoleKernel::class)) {
            $kernel = $this->eyepiece->outside(fn () => $app->make(ConsoleKernel::class));

            if (method_exists($kernel, 'rerouteSymfonyCommandEvents')) {
                $kernel->rerouteSymfonyCommandEvents();
            }
        }

        $this->eyepiece->listen(CommandStarting::class, $this->onStarting(...));
        $this->eyepiece->listen(CommandFinished::class, $this->onFinished(...));
    }

    public function finalize(Capture $capture): void
    {
        $this->phase?->stop();
        $this->phase = null;
    }

    public function reset(): void
    {
        $this->spans = [];
        $this->phase = null;
    }

    private function onStarting(CommandStarting $event): void
    {
        if ($this->isExcepted($event->command, $event->input)) {
            return;
        }

        $masker = $this->eyepiece->masker(self::class);
        $arguments = $masker->inputs($this->arguments($event->input));
        $options = $masker->inputs($this->options($event->input));

        // Inside another capture (Artisan::call() from a request, job or command) the command is a span of it.
        if ($this->eyepiece->active() !== null) {
            $this->startNested($event->command, $arguments, $options);

            return;
        }

        $this->startCapture($event->command, $arguments, $options);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<array-key, mixed>  $options
     */
    private function startNested(string $command, array $arguments, array $options): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $this->spans[] = $this->eyepiece->span($command, 'command')->start([
            'class' => $this->commandClass($command),
            'arguments' => $arguments,
            'options' => $options,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<array-key, mixed>  $options
     */
    private function startCapture(string $command, array $arguments, array $options): void
    {
        $startedAt = $this->laravelStart();
        $link = $this->traceLink();

        $this->eyepiece->start(
            CaptureType::Command,
            $startedAt,
            parentId: $link['parent_id'] ?? null,
            traceId: $link['trace_id'] ?? null,
            linkedVia: 'process',
        );
        $this->eyepiece->describe(name: 'artisan '.$command);
        $this->eyepiece->set('command', [
            'name' => $command,
            'class' => $this->commandClass($command),
            'arguments' => $arguments,
            'options' => $options,
        ]);

        // Booting the framework happened before the command started: show it as a phase from the very start.
        // LARAVEL_START is only defined by the artisan script; defining it in the test process would skew every later request capture.
        // @codeCoverageIgnoreStart
        if ($startedAt !== null) {
            $bootstrap = $this->eyepiece->span('bootstrap', 'phase');
            $this->eyepiece->startSpan($bootstrap, origin: false);
            $bootstrap->start = 0.0;
            $bootstrap->stop();
        }
        // @codeCoverageIgnoreEnd

        $this->phase = $this->eyepiece->span('command', 'phase');
        $this->eyepiece->startSpan($this->phase, origin: false);
        $this->phase->parentId = null;
    }

    /**
     * The trace a parent capture handed to this process through its
     * environment (ProcessLens with trace.processes).
     *
     * @return array{parent_id: string, trace_id: string}|null
     */
    private function traceLink(): ?array
    {
        if (! $this->eyepiece->config('trace.processes', true)) {
            return null;
        }

        return TraceContext::link(getenv(TraceContext::PARENT_ENV), getenv(TraceContext::TRACE_ENV));
    }

    /**
     * LARAVEL_START marks when the process began, so it only fits the first
     * command the process runs.
     */
    private function laravelStart(): ?float
    {
        // Only reachable with LARAVEL_START defined, see startCapture().
        // @codeCoverageIgnoreStart
        if ($this->usedLaravelStart) {
            return null;
        }
        // @codeCoverageIgnoreEnd

        if (! defined('LARAVEL_START')) {
            return null;
        }

        // @codeCoverageIgnoreStart
        $this->usedLaravelStart = true;

        return LARAVEL_START;
        // @codeCoverageIgnoreEnd
    }

    private function onFinished(CommandFinished $event): void
    {
        if ($this->isExcepted($event->command, $event->input)) {
            return;
        }

        if ($this->spans !== []) {
            $this->finishNested(array_pop($this->spans), $event->exitCode);

            return;
        }

        $capture = $this->eyepiece->active();

        if ($capture === null) {
            return;
        }

        if ($capture->type !== CaptureType::Command) {
            return;
        }

        $this->eyepiece->describe(status: (string) $event->exitCode);
        $this->eyepiece->set('command', ['exit_code' => $event->exitCode]);
        $this->eyepiece->finalize();
    }

    private function finishNested(Span $span, int $exitCode): void
    {
        $span->stop(['exit_code' => $exitCode]);

        $this->eyepiece->push('commands', [
            'name' => $span->name,
            'class' => $span->meta['class'] ?? null,
            'arguments' => $span->meta['arguments'] ?? [],
            'options' => $span->meta['options'] ?? [],
            'exit_code' => $exitCode,
            'failed' => $exitCode !== 0,
            'duration' => $span->duration(),
            'time' => $span->start,
        ]);
    }

    public function summarize(Capture $capture): Summary
    {
        $summary = $this->summarizeExitCode($capture);
        $rows = $capture->rows('commands');

        if ($rows === []) {
            return $summary;
        }

        $failed = [];
        $ms = 0.0;

        foreach ($rows as $row) {
            $ms += (float) ($row['duration'] ?? 0);

            if ($row['failed'] ?? false) {
                $failed[] = sprintf('Command %s failed (exit code %s).', $row['name'], $row['exit_code']);
            }
        }

        return $summary
            ->total('commands_ms', round($ms, 3), 'spent', Unit::Milliseconds)
            ->total('commands', count($rows), 'commands')
            ->total('commands_failed', count($failed), 'failed')
            ->errors(array_slice($failed, 0, 3));
    }

    /**
     * A command capture that exited with a non-zero code failed.
     */
    private function summarizeExitCode(Capture $capture): Summary
    {
        if ($capture->type !== CaptureType::Command) {
            return Summary::make();
        }

        $exitCode = $capture->section('command')['exit_code'] ?? 0;

        if ($exitCode === 0) {
            return Summary::make();
        }

        return Summary::make()->error(sprintf('Command exited with code %s.', $exitCode));
    }

    /**
     * The class behind a command name; null for closure commands.
     */
    private function commandClass(string $name): ?string
    {
        try {
            $commands = $this->eyepiece->outside(fn (): array => $this->app->make(ConsoleKernel::class)->all());
        } catch (Throwable) {
            return null;
        }

        $command = $commands[$name] ?? null;

        if ($command === null) {
            return null;
        }

        if ($command instanceof ClosureCommand) {
            return null;
        }

        return $command::class;
    }

    private function isExcepted(string $command, InputInterface $input): bool
    {
        // Patterns may name arguments too, e.g. 'queue:work redis'; only scalars can be written that way.
        $scalarArguments = array_filter(
            array_values($this->arguments($input)),
            fn (mixed $value): bool => is_scalar($value),
        );
        $withArguments = trim($command.' '.implode(' ', array_map('strval', $scalarArguments)));

        foreach ((array) $this->eyepiece->config('except.commands', []) as $pattern) {
            if (Str::is($pattern, $command) || Str::is($pattern, $withArguments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The arguments without the command name, which Artisan::call() passes
     * as the first one.
     *
     * @return array<string, mixed>
     */
    private function arguments(InputInterface $input): array
    {
        return array_filter(
            array_diff_key($input->getArguments(), ['command' => true, 0 => true]),
            fn (mixed $value): bool => $value !== null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function options(InputInterface $input): array
    {
        return array_filter($input->getOptions(), fn (mixed $value): bool => $value !== null && $value !== false && $value !== []);
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.command')
            ->includes([
                'commands' => Presentation::table()
                    ->label('Commands')
                    ->columns(['name', 'class', 'exit_code' => 'Exit', 'failed', 'duration'])
                    ->detailsOnly(['arguments', 'options'])
                    ->format(['name' => 'code', 'exit_code' => 'status', 'failed' => ['flag', 'failed', null, 'muted', 'bad']])
                    ->highlight(['failed' => 'bad'])
                    ->groupBy(['name' => 'by command']),
            ]);
    }
}
