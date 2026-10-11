<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use Closure;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Eyepiece;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\VarDumper;

/**
 * Captures dump() and dd() output with the place it was called from, then
 * hands the value to whatever dumper was registered before so the developer
 * still sees it. dd() exits the process; the shutdown handler stores the
 * capture anyway.
 */
final class DumpLens implements Advises, Lens, Summarizable
{
    /** @var callable|null */
    private $previous;

    private Closure $handler;

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'dumps';
    }

    public function description(): string
    {
        return 'Values passed to dump() and dd(): the dumped value as text, its type, the label and whether it came from dd() (which ended the process - the capture is stored at shutdown with ended_by=shutdown).';
    }

    public function advice(): string
    {
        return 'Look here for values passed to dump() and dd().';
    }

    public function boot(): void
    {
        $this->handler = function (mixed $value, ?string $label = null): void {
            $this->record($value, $label);
            $this->forward($value, $label);
        };

        $this->previous = VarDumper::setHandler($this->handler);
    }

    public function summarize(Capture $capture): array
    {
        $rows = $capture->rows('dumps');

        if ($rows === []) {
            return [];
        }

        $dd = count(array_filter($rows, fn (array $row): bool => (bool) $row['is_dd']));

        return [
            'totals' => ['dumps' => count($rows), 'dd_calls' => $dd],
            'warnings' => $dd > 0 ? ['dd() ended the process before the response was sent.'] : [],
        ];
    }

    private function record(mixed $value, ?string $label): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $dumper = new CliDumper();
        $dumper->setColors(false);

        $this->eyepiece->push('dumps', [
            'value' => (string) $dumper->dump((new VarCloner())->cloneVar($value), true),
            'type' => get_debug_type($value),
            'label' => $label,
            'is_dd' => $this->calledFromDd(),
        ]);
    }

    private function forward(mixed $value, ?string $label): void
    {
        if ($this->previous !== null) {
            ($this->previous)($value, $label);

            return;
        }

        // No dumper was registered before us: let VarDumper build its default,
        // dump once through it, and keep it for the next call.
        VarDumper::setHandler(null);
        VarDumper::dump($value, $label);
        $this->previous = VarDumper::setHandler($this->handler);
    }

    private function calledFromDd(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            if (($frame['function'] ?? null) === 'dd' && ! isset($frame['class'])) {
                return true;
            }
        }

        return false;
    }
}
