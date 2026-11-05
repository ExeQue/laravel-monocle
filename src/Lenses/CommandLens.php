<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Finalizable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\ProvidesSections;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Terminal;
use ExeQue\Monocle\Support\TraceContext;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Artisan commands become captures of type command; a command invoked while
 * another capture is active (Artisan::call inside a request or a command)
 * becomes a span instead, and a row of the commands section when it finishes.
 * A command run at a terminal prints the id of its capture once the application
 * terminates, below any exception Laravel rendered for it.
 */
final class CommandLens implements Advises, Configurable, Finalizable, Lens, Presentable, ProvidesSections, Resettable, Summarizable
{
    private bool $usedLaravelStart = false;

    /** @var list<Span> open nested command spans */
    private array $spans = [];

    /** How many excepted commands are running; whatever they call is excepted with them. */
    private int $exceptedDepth = 0;

    private Application $app;

    private ?Span $phase = null;

    /** Which runs print their capture id to the terminal: 'always', 'failure' or 'off'. */
    private string $printCapture = 'always';

    /** @var array{string, OutputInterface}|null the stored capture id of the run and its output, until printed */
    private ?array $pendingRun = null;

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'command';
    }

    public function sections(): array
    {
        return ['commands'];
    }

    public function description(): string
    {
        return 'Artisan command facts for captures of type command: name, arguments and options (both masked), exit code (a non-zero exit code is an error). Section commands has one row per Artisan::call() made while any capture (request, job, command) is active: name, class, arguments and options (masked), exit_code, failed (non-zero exit code), duration, time and origin; each is also a command span in the timeline. Long-running workers (queue:work, horizon, schedule:work) are excluded; the work they do is recorded as job captures. A command started through the Process facade while a capture records joins its trace as a child (MONOCLE_TRACE_ID and MONOCLE_PARENT_ID in the environment).';
    }

    public function advice(): string
    {
        return 'Look here for an artisan command\'s arguments, options and exit code.';
    }

    public function configure(array $options): void
    {
        $this->printCapture = match ($options['print_capture'] ?? $this->printCapture) {
            'always' => 'always',
            'failure' => 'failure',
            default => 'off',
        };
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

        // The console kernel renders an uncaught exception after CommandFinished; terminating runs after that.
        $app->terminating($this->printRun(...));
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
        if ($this->exceptedDepth > 0 || $this->isExcepted($event->command, $event->input)) {
            $this->exceptedDepth++;

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
        if ($this->exceptedDepth > 0) {
            $this->exceptedDepth--;

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
        $stored = $this->eyepiece->finalize();

        // Only a capture that was stored can be looked up; a discarded one has nothing to show.
        $prints = $this->printCapture === 'always' || ($this->printCapture === 'failure' && $event->exitCode !== 0);
        $this->pendingRun = $prints && $stored !== null ? [$stored->id, $event->output] : null;
    }

    private function printRun(): void
    {
        if ($this->pendingRun === null) {
            return;
        }

        [$id, $output] = $this->pendingRun;
        $this->pendingRun = null;

        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }

        if ($output->isQuiet()) {
            return;
        }

        $terminal = $this->app->make(Terminal::class);

        // A file, a pipe or a scheduler's log is left as the command wrote it.
        if (! $terminal->interactive($output)) {
            return;
        }

        $dashboard = $this->dashboardUrl();
        $url = $dashboard !== null ? $dashboard.'#'.$id : null;

        // A terminal that opens links gets the id as one; anywhere else the url is printed below it.
        $linked = $url !== null && $output->isDecorated() && $terminal->opensLinks();

        $lines = [
            sprintf(
                'Captured this run as %s',
                $linked ? sprintf('<href=%s;fg=#c08429;options=bold,underscore>%s</>', $url, $id) : sprintf('<fg=#c08429;options=bold>%s</>', $id),
            ),
            sprintf('<fg=gray>php artisan monocle:show %s</>', $id),
        ];

        if ($url !== null && ! $linked) {
            $lines[] = sprintf('<fg=gray>%s</>', $url);
        }

        $output->writeln(['', ...$this->box('Monocle', $lines, $output)]);
    }

    /**
     * Draws a rounded bronze box around the lines, with the title in its top edge.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function box(string $title, array $lines, OutputInterface $output): array
    {
        $visible = fn (string $line): int => Helper::width(Helper::removeDecoration($output->getFormatter(), $line));
        $width = max([...array_map($visible, $lines), $visible($title) + 2]);
        $edge = fn (string $line): string => '<fg=#c08429>'.$line.'</>';

        return [
            $edge('╭─ ').'<fg=#c08429;options=bold>'.$title.'</>'.$edge(' '.str_repeat('─', $width - $visible($title) - 1).'╮'),
            ...array_map(fn (string $line): string => $edge('│').' '.$line.str_repeat(' ', $width - $visible($line)).' '.$edge('│'), $lines),
            $edge('╰'.str_repeat('─', $width + 2).'╯'),
        ];
    }

    /**
     * The dashboard opens the capture named by the location hash.
     */
    private function dashboardUrl(): ?string
    {
        if (! $this->eyepiece->config('dashboard.enabled', true) || ! $this->app->make('router')->has('monocle.dashboard')) {
            return null;
        }

        try {
            return $this->app->make('url')->route('monocle.dashboard');
        } catch (Throwable) {
            return null;
        }
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

        $rows = collect($rows);
        $failed = $rows
            ->filter(fn (array $row): bool => (bool) ($row['failed'] ?? false))
            ->map(fn (array $row): string => sprintf('Command %s failed (exit code %s).', $row['name'], $row['exit_code']));
        $ms = $rows->sum(fn (array $row): float => (float) ($row['duration'] ?? 0));

        return $summary
            ->total('commands_ms', round($ms, 3), 'spent', Unit::Milliseconds)
            ->total('commands', $rows->count(), 'commands')
            ->total('commands_failed', $failed->count(), 'failed')
            ->errors($failed->take(3));
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
