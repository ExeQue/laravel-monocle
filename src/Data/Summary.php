<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Data;

/**
 * What a lens adds to a capture's summary: named totals for the overview
 * (and monocle-show), and warnings that flag the capture and its tab.
 */
final class Summary
{
    /**
     * @param  array<string, mixed>  $totals  name => value; names starting with the lens key group under its tab
     * @param  list<string>  $warnings
     */
    public function __construct(
        private array $totals = [],
        private array $warnings = [],
    ) {
    }

    public static function make(): self
    {
        return new self();
    }

    public function total(string $name, mixed $value): self
    {
        $this->totals[$name] = $value;

        return $this;
    }

    public function warning(string $warning): self
    {
        $this->warnings[] = $warning;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function totals(): array
    {
        return $this->totals;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return array_values($this->warnings);
    }
}
