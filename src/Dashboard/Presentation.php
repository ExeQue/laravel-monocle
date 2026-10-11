<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Dashboard;

use ExeQue\Monocle\Support\Concerns\Immutable;

/**
 * How a lens's section shows in the dashboard. Everything is optional; what
 * is left out is worked out from the rows, as for any section. Every fluent
 * method returns a new presentation.
 */
final readonly class Presentation
{
    use Immutable;

    private function __construct(
        public ?string $label = null,
        /** @var array<string, string|null> column => label (null: from the column name) */
        public array $columns = [],
        /** @var list<string> */
        public array $detailsOnly = [],
        /** @var array<string, string> field => chip label */
        public array $groupings = [],
        public ?string $detailsView = null,
        public ?string $tabView = null,
    ) {
    }

    public static function table(): self
    {
        return new self();
    }

    /**
     * The tab's name; the section key, humanised, by default.
     */
    public function label(string $label): self
    {
        return $this->with(['label' => $label]);
    }

    /**
     * The table's columns, in order: a list of fields, or field => header.
     *
     * @param  array<int|string, string>  $columns
     */
    public function columns(array $columns): self
    {
        $normalized = [];

        foreach ($columns as $field => $label) {
            is_int($field) ? $normalized[$label] = null : $normalized[$field] = $label;
        }

        return $this->with(['columns' => $normalized]);
    }

    /**
     * Fields shown when a row is expanded, never as a column.
     *
     * @param  list<string>  $fields
     */
    public function detailsOnly(array $fields): self
    {
        return $this->with(['detailsOnly' => $fields]);
    }

    /**
     * Fields the rows can be grouped by, with the label of their chip.
     *
     * @param  array<string, string>  $groupings  field => label, e.g. ['endpoint' => 'by endpoint']
     */
    public function groupBy(array $groupings): self
    {
        return $this->with(['groupings' => $groupings]);
    }

    /**
     * A Blade view rendering an expanded row instead of the field list. It
     * receives $row (the row as stored) and $capture.
     */
    public function details(string $view): self
    {
        return $this->with(['detailsView' => $view]);
    }

    /**
     * A Blade view for the whole tab, instead of the table. It is part of the
     * dashboard page, so it can use the dashboard's Alpine state (the open
     * tab's rows are in tabData) and its components, such as
     * <x-monocle::kv-table> and <x-monocle::section>.
     */
    public function tab(string $view): self
    {
        return $this->with(['tabView' => $view]);
    }

    /**
     * @return array{label: string|null, columns: list<string>, labels: array<string, string>, details_only: list<string>, groupings: list<array{0: string, 1: string}>, details_view: bool}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'columns' => array_keys($this->columns),
            'labels' => array_filter($this->columns, 'is_string'),
            'details_only' => $this->detailsOnly,
            'groupings' => array_map(fn (string $field, string $label): array => [$field, $label], array_keys($this->groupings), $this->groupings),
            'details_view' => $this->detailsView !== null,
        ];
    }
}
