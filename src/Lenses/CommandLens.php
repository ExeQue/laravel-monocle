<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Finalizable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
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
 * becomes a span instead.
 */
final class CommandLens implements Advises, Finalizable, Lens, Resettable
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
        return 'Artisan command facts for captures of type command: name, arguments, options (masked), exit code. Nested Artisan::call() invocations are command spans in the timeline. Long-running workers (queue:work, horizon, schedule:work) are excluded; the work they do is recorded as job captures.';
    }

    public function advice(): string
    {
        return 'Look here for an artisan command\'s arguments, options and exit code.';
    }

    public function boot(Application $app): void
    {
        $this->app = $app;

        // The Laravel command events are bridged from Symfony's only once the
        // kernel has been asked to; most applications do this during bootstrap,
        // but it is cheap and idempotent to make sure.
        if ($app->bound(ConsoleKernel::class)) {
            $kernel = $app->make(ConsoleKernel::class);

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

        $arguments = $this->arguments($event->input);
        $options = $this->eyepiece->masker()->inputs($this->options($event->input));

        if ($this->eyepiece->active() !== null) {
            if (! $this->eyepiece->recording()) {
                return;
            }

            $this->spans[] = $this->eyepiece->span($event->command, 'command')->start([
                'class' => $this->commandClass($event->command),
                'arguments' => $arguments,
                'options' => $options,
            ]);

            return;
        }

        $startedAt = null;

        if (! $this->usedLaravelStart && defined('LARAVEL_START')) {
            $startedAt = LARAVEL_START;
            $this->usedLaravelStart = true;
        }

        $this->eyepiece->start(CaptureType::Command, $startedAt);
        $this->eyepiece->describe(name: 'artisan '.$event->command);
        $this->eyepiece->set('command', [
            'name' => $event->command,
            'class' => $this->commandClass($event->command),
            'arguments' => $arguments,
            'options' => $options,
        ]);

        if ($startedAt !== null) {
            $bootstrap = $this->eyepiece->span('bootstrap', 'phase');
            $this->eyepiece->startSpan($bootstrap, origin: false);
            $bootstrap->start = 0.0;
            $bootstrap->stop();
        }

        $this->phase = $this->eyepiece->span('command', 'phase');
        $this->eyepiece->startSpan($this->phase, origin: false);
        $this->phase->parentId = null;
    }

    private function onFinished(CommandFinished $event): void
    {
        if ($this->isExcepted($event->command, $event->input)) {
            return;
        }

        if ($this->spans !== []) {
            array_pop($this->spans)->stop(['exit_code' => $event->exitCode]);

            return;
        }

        $capture = $this->eyepiece->active();

        if ($capture === null || $capture->type !== CaptureType::Command) {
            return;
        }

        $this->eyepiece->describe(status: (string) $event->exitCode);
        $this->eyepiece->set('command', ['exit_code' => $event->exitCode]);
        $this->eyepiece->finalize();
    }

    /**
     * The class behind a command name; null for closure commands.
     */
    private function commandClass(string $name): ?string
    {
        try {
            $command = $this->app->make(ConsoleKernel::class)->all()[$name] ?? null;
        } catch (Throwable) {
            return null;
        }

        return $command === null || $command instanceof ClosureCommand ? null : $command::class;
    }

    private function isExcepted(string $command, InputInterface $input): bool
    {
        $withArguments = trim($command.' '.implode(' ', array_map('strval', array_filter(
            array_values(array_diff_key($this->arguments($input), ['command' => true])),
            fn (mixed $value): bool => is_scalar($value),
        ))));

        foreach ((array) $this->eyepiece->config('except.commands', []) as $pattern) {
            if (Str::is($pattern, $command) || Str::is($pattern, $withArguments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function arguments(InputInterface $input): array
    {
        return array_filter($input->getArguments(), fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(InputInterface $input): array
    {
        return array_filter($input->getOptions(), fn (mixed $value): bool => $value !== null && $value !== false && $value !== []);
    }
}
