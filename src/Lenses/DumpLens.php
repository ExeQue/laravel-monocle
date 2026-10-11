<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use Closure;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Dumps\TokenDumper;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\VarDumper;

/**
 * Captures dump() and dd() output with the place it was called from, then
 * hands the value to whatever dumper was registered before so the developer
 * still sees it. dd() exits the process; the shutdown handler stores the
 * capture anyway.
 */
final class DumpLens implements Advises, Lens, Presentable, Summarizable
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
        return 'Values passed to dump() and dd(): the dumped value as text (properties listed under monocle.mask.objects masked, such as the password of a user), the same text as [style, text] pairs VarDumper styled (tokens, for the dashboard), its type, the label and whether it came from dd() (which ended the process - the capture is stored at shutdown with ended_by=shutdown).';
    }

    public function advice(): string
    {
        return 'Look here for values passed to dump() and dd().';
    }

    public function boot(): void
    {
        $record = $this->eyepiece->wrap($this->record(...));

        $this->handler = function (mixed $value, ?string $label = null) use ($record): void {
            $record($value, $label);
            $this->forward($value, $label);
        };

        $this->previous = VarDumper::setHandler($this->handler);
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('dumps');

        if ($rows === []) {
            return Summary::make();
        }

        $dd = count(array_filter($rows, fn (array $row): bool => (bool) $row['is_dd']));

        return Summary::make()
            ->total('dumps', count($rows), 'dumps')
            ->total('dd_calls', $dd, 'dd() calls')
            ->when($dd > 0)
            ->warning('dd() ended the process before the response was sent.');
    }

    private function record(mixed $value, ?string $label): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        [$text, $tokens] = new TokenDumper()->tokenize($this->cloner()->cloneVar($value));

        $this->eyepiece->push('dumps', [
            'value' => $text,
            'tokens' => $tokens,
            'type' => get_debug_type($value),
            'label' => $label,
            'is_dd' => $this->calledFromDd(),
        ]);
    }

    /**
     * A cloner that masks the properties monocle.mask.objects lists, by the class or interface they belong to.
     */
    private function cloner(): VarCloner
    {
        $masker = $this->eyepiece->masker(self::class);
        $cloner = new VarCloner();
        $mask = fn (object $object, array $properties): array => $this->maskProperties($masker->objectMasks($object), $properties);

        foreach ($masker->maskedClasses() as $class) {
            $cloner->addCasters([$class => $mask]);
        }

        return $cloner;
    }

    /**
     * @param  array<string, Closure>  $masks  property => mask
     * @param  array<string, mixed>  $properties  as VarDumper names them: "\0*\0name" for protected, "\0Class\0name" for private
     * @return array<string, mixed>
     */
    private function maskProperties(array $masks, array $properties): array
    {
        foreach ($properties as $name => $value) {
            $property = str_contains($name, "\0") ? substr($name, strrpos($name, "\0") + 1) : $name;

            if (isset($masks[$property])) {
                $properties[$name] = $this->masked($masks[$property], $value, $property);
            }
        }

        // An Eloquent model keeps its columns in arrays, not in properties.
        foreach (["\0*\0attributes", "\0*\0original"] as $bag) {
            if (! is_array($properties[$bag] ?? null)) {
                continue;
            }

            foreach (array_intersect_key($masks, $properties[$bag]) as $key => $mask) {
                $properties[$bag][$key] = $this->masked($mask, $properties[$bag][$key], $key);
            }
        }

        return $properties;
    }

    /**
     * An empty value has nothing to hide; it is left as it is so the dump stays true.
     */
    private function masked(Closure $mask, mixed $value, string $key): mixed
    {
        if ($value === null) {
            return null;
        }

        return $mask($value, $key);
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
            // The global dd() helper, not a dd() method such as Collection::dd().
            if (isset($frame['class'])) {
                continue;
            }

            if (($frame['function'] ?? null) === 'dd') {
                return true;
            }
        }

        return false;
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.dumps')
            ->detailsOnly(['tokens'])
            ->details('monocle::details.dump');
    }
}
