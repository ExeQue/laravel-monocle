<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Dashboard;

/**
 * How a lens's section shows in the dashboard. Everything is optional; what
 * is left out is worked out from the rows, as for any section.
 */
final class Presentation
{
    private ?string $label = null;

    /** @var array<string, string|null> column => label (null: from the column name) */
    private array $columns = [];

    /** @var list<string> */
    private array $detailsOnly = [];

    /** @var array<string, string> field => chip label */
    private array $groupings = [];

    private ?string $detailsView = null;

    private ?string $tabView = null;

    public static function table(): self
    {
        return new self();
    }

    /**
     * The tab's name; the section key, humanised, by default.
     */
    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * The table's columns, in order: a list of fields, or field => header.
     *
     * @param  array<int|string, string>  $columns
     */
    public function columns(array $columns): self
    {
        $this->columns = [];

        foreach ($columns as $field => $label) {
            is_int($field) ? $this->columns[$label] = null : $this->columns[$field] = $label;
        }

        return $this;
    }

    /**
     * Fields shown when a row is expanded, never as a column.
     *
     * @param  list<string>  $fields
     */
    public function detailsOnly(array $fields): self
    {
        $this->detailsOnly = $fields;

        return $this;
    }

    /**
     * Fields the rows can be grouped by, with the label of their chip.
     *
     * @param  array<string, string>  $groupings  field => label, e.g. ['endpoint' => 'by endpoint']
     */
    public function groupBy(array $groupings): self
    {
        $this->groupings = $groupings;

        return $this;
    }

    /**
     * A Blade view rendering an expanded row instead of the field list. It
     * receives $row (the row as stored) and $capture.
     */
    public function details(string $view): self
    {
        $this->detailsView = $view;

        return $this;
    }

    /**
     * A Blade view for the whole tab, instead of the table. It is part of the
     * dashboard page, so it can use the dashboard's Alpine state (the open
     * tab's rows are in tabData) and its components, such as
     * <x-monocle::kv-table> and <x-monocle::section>.
     */
    public function tab(string $view): self
    {
        $this->tabView = $view;

        return $this;
    }

    public function tabView(): ?string
    {
        return $this->tabView;
    }

    public function detailsView(): ?string
    {
        return $this->detailsView;
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
