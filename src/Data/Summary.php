<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Data;

use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\Exceptions\InvalidSummaryTotalException;
use ExeQue\Monocle\Support\Concerns\Immutable;
use Illuminate\Support\Traits\Conditionable;

/**
 * What a lens adds to a capture's summary: named totals for the overview
 * (and monocle-show), and warnings and errors that flag the capture and
 * its tab.
 * Every fluent method returns a new summary.
 */
final readonly class Summary
{
    use Conditionable;
    use Immutable;

    /**
     * @param  array<string, mixed>  $totals  name => value; names starting with the lens key group under its tab
     * @param  array<string, string>  $labels  name => what the value counts, e.g. "failed"
     * @param  array<string, string>  $units  name => unit: a Unit value, or a suffix such as a currency
     * @param  list<string>  $warnings  things worth a look
     * @param  list<string>  $errors  things that failed
     */
    public function __construct(
        public array $totals = [],
        public array $labels = [],
        public array $units = [],
        public array $warnings = [],
        public array $errors = [],
    ) {
    }

    public static function make(): self
    {
        return new self();
    }

    /**
     * One total. The label says what the value counts ("failed", "time"); the
     * name is used when it is left out. A Unit formats the value; a string is
     * shown after it.
     */
    public function total(string $name, mixed $value, ?string $label = null, Unit|string|null $unit = null): self
    {
        if ($value === null) {
            throw InvalidSummaryTotalException::withoutValue($name);
        }

        $labels = $this->labels;

        if ($label === null) {
            unset($labels[$name]);
        } else {
            $labels[$name] = $label;
        }

        return $this->with(['labels' => $labels])->totals([$name => $value], $unit);
    }

    /**
     * Several totals as name => value, all in the same unit. Null values are
     * kept and left out of the overview.
     *
     * @param  array<string, mixed>  $totals
     */
    public function totals(array $totals, Unit|string|null $unit = null): self
    {
        $units = $this->units;

        foreach (array_keys($totals) as $name) {
            if ($unit === null) {
                unset($units[$name]);
            } else {
                $units[$name] = $unit instanceof Unit ? $unit->value : $unit;
            }
        }

        return $this->with([
            'totals' => [...$this->totals, ...$totals],
            'units' => $units,
        ]);
    }

    public function warning(string $warning): self
    {
        return $this->warnings([$warning]);
    }

    /**
     * @param  list<string>  $warnings
     */
    public function warnings(array $warnings): self
    {
        return $this->with(['warnings' => [...$this->warnings, ...array_values($warnings)]]);
    }

    public function error(string $error): self
    {
        return $this->errors([$error]);
    }

    /**
     * @param  list<string>  $errors
     */
    public function errors(array $errors): self
    {
        return $this->with(['errors' => [...$this->errors, ...array_values($errors)]]);
    }
}
