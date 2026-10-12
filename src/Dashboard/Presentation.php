<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Dashboard;

use ExeQue\Monocle\Support\Concerns\Immutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Enumerable;

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
        /** @var list<string> */
        public array $includes = [],
        /** @var array<string, self> included or alongside section => how it is shown */
        public array $included = [],
        /** @var array<string, list<mixed>> field => [format, ...arguments] */
        public array $formats = [],
        /** @var array<string, string> field => tone of rows where it is set */
        public array $highlights = [],
        public bool $showsTotals = true,
        public bool $showsProblems = true,
    ) {
    }

    public static function table(): self
    {
        return self::make();
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
     * @param  array<int|string, string>|Enumerable<int|string, string>  $columns
     */
    public function columns(array|Enumerable $columns): self
    {
        // A listed field has no header of its own; field => header names one.
        $normalized = collect($columns)->mapWithKeys(fn (string $label, int|string $field): array => is_int($field) ? [$label => null] : [$field => $label]);

        return $this->with(['columns' => $normalized->all()]);
    }

    /**
     * Fields shown when a row is expanded, never as a column.
     *
     * @param  list<string>|Enumerable<int, string>  $fields
     */
    public function detailsOnly(array|Enumerable $fields): self
    {
        return $this->with(['detailsOnly' => Arr::from($fields)]);
    }

    /**
     * Fields the rows can be grouped by, with the label of their chip.
     *
     * @param  array<string, string>|Enumerable<string, string>  $groupings  field => label, e.g. ['endpoint' => 'by endpoint']
     */
    public function groupBy(array|Enumerable $groupings): self
    {
        return $this->with(['groupings' => Arr::from($groupings)]);
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
     * Other sections this tab shows, such as auth events on the request tab.
     * They get no tab of their own while this one is shown. Give a section a
     * presentation of its own to format its table: ['changes' => Presentation::table()->format(...)].
     *
     * @param  array<int|string, string|self>|Enumerable<int|string, string|self>  $sections
     */
    public function includes(array|Enumerable $sections): self
    {
        $sections = collect($sections);
        // A section listed by name keeps its own presentation; section => presentation formats it here.
        $included = $sections->whereInstanceOf(self::class)->mapWithKeys(fn (self $section, int|string $key): array => [(string) $key => $section]);
        $keys = $sections->map(fn (string|self $section, int|string $key): string => $section instanceof self ? (string) $key : $section);

        return $this->with(['includes' => $keys->values()->all(), 'included' => $included->all()]);
    }

    /**
     * Other sections the lens writes that get a tab of their own, such as
     * the facts of a job capture beside the jobs it dispatched.
     *
     * @param  array<string, self>|Enumerable<string, self>  $sections  section => how it is shown
     */
    public function alongside(array|Enumerable $sections): self
    {
        return $this->with(['included' => [...$this->included, ...Arr::from($sections)]]);
    }

    /**
     * How fields show in the table, by name:
     *
     *     'http-status'              a status code, coloured by class; empty means the call failed
     *     'status'                   a status word, coloured: processed, failed, ...
     *     'level'                    a log level, coloured by severity
     *     ['flag', 'yes', 'no']      true or false as a word; false in red, true in green
     *                                (['flag', 'yes', 'no', 'warn', 'muted'] picks both colours; null leaves a word out)
     *     'bool'                     true or false, true in green
     *     'code'                     the value in full in a monospace font, such as a command
     *     ['truncate', 80]           cut after that many characters; the full text in a tooltip
     *     ['prefix', '#']            the value after a prefix
     *     ['reference', 'repeat of #'] a badge pointing at another row
     *     ['highlight', ['miss'], 'warn'] the listed values coloured
     *     'fields'                   a key/value map as a short list
     *     'rules'                    validation rules per field
     *     ['capture', 'job_id']      a link to the capture started from this row: the newest child
     *                                capture whose job_id is the value, or "pending" until there is one
     *
     * @param  array<string, string|list<mixed>>|Enumerable<string, string|list<mixed>>  $formats  field => format or [format, ...arguments]
     */
    public function format(array|Enumerable $formats): self
    {
        $normalized = collect($formats)->map(fn (string|array $format): array => (array) $format);

        return $this->with(['formats' => [...$this->formats, ...$normalized->all()]]);
    }

    /**
     * Tints rows where a field is set, such as repeated reads.
     *
     * @param  array<string, 'warn'|'bad'>|Enumerable<string, 'warn'|'bad'>  $fields  field => tone
     */
    public function highlight(array|Enumerable $fields): self
    {
        return $this->with(['highlights' => [...$this->highlights, ...Arr::from($fields)]]);
    }

    /**
     * Leaves the section's totals off the top of its tab (they stay on the overview).
     */
    public function withoutTotals(): self
    {
        return $this->with(['showsTotals' => false]);
    }

    /**
     * Leaves the section's warnings and errors off the top of its tab, for a tab whose
     * rows already are those problems (they stay on the overview).
     */
    public function withoutProblems(): self
    {
        return $this->with(['showsProblems' => false]);
    }

    /**
     * @return array{label: string|null, columns: list<string>, labels: array<string, string>, details_only: list<string>, groupings: list<array{0: string, 1: string}>, details_view: bool, includes: list<string>, formats: array<string, list<mixed>>, highlights: array<string, string>, shows_totals: bool, shows_problems: bool}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'columns' => array_keys($this->columns),
            'labels' => array_filter($this->columns, 'is_string'),
            'details_only' => $this->detailsOnly,
            'groupings' => collect($this->groupings)->map(fn (string $label, string $field): array => [$field, $label])->values()->all(),
            'details_view' => $this->detailsView !== null,
            'includes' => $this->includes,
            'formats' => $this->formats,
            'highlights' => $this->highlights,
            'shows_totals' => $this->showsTotals,
            'shows_problems' => $this->showsProblems,
        ];
    }
}
