<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Console\Concerns\OutputsJson;
use ExeQue\Monocle\Console\Concerns\RendersForHumans;
use ExeQue\Monocle\Dashboard\Format;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/** @internal */
final class ShowCommand extends Command
{
    use OutputsJson;
    use RendersForHumans;

    /**
     * More columns than this per section and the rest are left out of the table.
     */
    private const int MAX_COLUMNS = 7;

    protected $name = 'monocle:show';

    protected $description = 'Show a capture summary, timeline or sections (same output as the monocle-show MCP tool)';

    public function handle(Viewfinder $viewfinder): int
    {
        return $this->respond($viewfinder->show([
            'id' => $this->argument('id'),
            'sections' => $this->option('section') ?: ['summary'],
            'kinds' => $this->option('kind'),
            'verbose' => (bool) $this->option('verbose-origin'),
            ...$this->optionArgs(['limit', 'search']),
        ]), $this->render(...));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(array $data): void
    {
        $summary = $data['summary'] ?? null;

        if (is_array($summary)) {
            $this->header($summary);
            $this->summary($summary);
        }

        if (! is_array($summary)) {
            intro('Capture '.$data['id']);
        }

        foreach ($data as $key => $section) {
            if (in_array($key, ['id', 'available_sections', 'summary'], true)) {
                continue;
            }

            info(Str::headline((string) $key));

            if ($key === 'timeline') {
                $this->timeline((array) $section, (string) $data['id']);

                continue;
            }

            $this->section((array) $section, (string) $data['id']);
        }

        $this->availableSections((array) ($data['available_sections'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function header(array $summary): void
    {
        intro($summary['name'].' ['.$summary['type'].']');

        $line = [
            'status '.$this->cell($summary['status'] ?? null),
            $this->ms($summary['totals']['duration_ms'] ?? null),
            'started '.$this->ago($summary['started_at'] ?? null),
        ];

        $details = [implode(' | ', $line), 'Id: '.$summary['id']];

        if (($summary['label'] ?? null) !== null) {
            $details[] = 'Label: '.$summary['label'];
        }

        if (($summary['tags'] ?? []) !== []) {
            $details[] = 'Tags: '.$this->cell($summary['tags'], 200);
        }

        if (($summary['parent_id'] ?? null) !== null) {
            $details[] = 'Parent: '.$summary['parent_id'].' (monocle:show '.$summary['parent_id'].')';
        }

        if (($summary['children'] ?? []) !== []) {
            $details[] = 'Children: '.count($summary['children']).' (monocle:trace '.$summary['id'].')';
        }

        note(implode("\n", $details));
    }

    /**
     * @param  array{totals?: array<string, mixed>, labels?: array<string, string>, units?: array<string, string>, groups?: array<string, string>, headlines?: list<string>, warnings?: list<string>, errors?: list<string>, breakdown?: array{phases?: list<array{name: string, ms: float, percent: float, count: int}>, categories?: list<array{name: string, ms: float, percent: float, count: int}>}}  $summary
     */
    private function summary(array $summary): void
    {
        $this->flags($summary['warnings'] ?? [], $summary['errors'] ?? []);
        $this->headlineTotals($summary);
        $this->breakdown('Phase', $summary['breakdown']['phases'] ?? []);
        $this->breakdown('Category', $summary['breakdown']['categories'] ?? []);
        $this->groupedTotals($summary);
    }

    /**
     * The totals the summary picks as headlines, in their own table on top.
     *
     * @param  array{totals?: array<string, mixed>, labels?: array<string, string>, units?: array<string, string>, headlines?: list<string>}  $summary
     */
    private function headlineTotals(array $summary): void
    {
        $totals = $summary['totals'] ?? [];
        $labels = $summary['labels'] ?? [];
        $units = $summary['units'] ?? [];
        $rows = [];

        foreach ($summary['headlines'] ?? [] as $key) {
            $value = $totals[$key] ?? null;

            if ($this->isEmptyTotal($value)) {
                continue;
            }

            $label = Str::ucfirst($labels[$key] ?? $key);
            $rows[] = [$label, $this->total($value, $units[$key] ?? null)];
        }

        if ($rows === []) {
            return;
        }

        table(['Total', 'Value'], $rows);
    }

    /**
     * @param  list<array{name: string, ms: float, percent: float, count: int}>  $parts
     */
    private function breakdown(string $header, array $parts): void
    {
        if ($parts === []) {
            return;
        }

        $rows = [];

        foreach ($parts as $part) {
            $rows[] = [
                $part['name'],
                $this->ms($part['ms']),
                $part['percent'].'%',
                (string) $part['count'],
                $this->bar((float) $part['percent']),
            ];
        }

        table([$header, 'Time', '%', 'Count', ''], $rows);
    }

    /**
     * Every other total, grouped by the section that recorded it. Array
     * totals are flattened to one row per dot.notated key.
     *
     * @param  array{totals?: array<string, mixed>, labels?: array<string, string>, units?: array<string, string>, groups?: array<string, string>, headlines?: list<string>}  $summary
     */
    private function groupedTotals(array $summary): void
    {
        $headlines = $summary['headlines'] ?? [];
        $labels = $summary['labels'] ?? [];
        $units = $summary['units'] ?? [];
        $grouped = [];

        foreach ($summary['totals'] ?? [] as $key => $value) {
            if (in_array($key, $headlines, true)) {
                continue;
            }

            if ($this->isEmptyTotal($value)) {
                continue;
            }

            $section = $summary['groups'][$key] ?? 'other';
            $label = $labels[$key] ?? str_replace('_', ' ', Str::chopStart($key, $section.'_'));

            if (! is_array($value)) {
                $grouped[] = [$section, $label, $this->total($value, $units[$key] ?? null)];

                continue;
            }

            foreach (Format::dot($value, $label) as [$dotKey, $item]) {
                if ($this->isEmptyTotal($item)) {
                    continue;
                }

                $grouped[] = [$section, $dotKey, $this->cell($item)];
            }
        }

        if ($grouped === []) {
            return;
        }

        usort($grouped, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        // Name each section once, on the first of its rows.
        $previous = null;

        foreach ($grouped as $index => $row) {
            $grouped[$index][0] = $row[0] === $previous ? '' : $row[0];
            $previous = $row[0];
        }

        table(['Section', 'Total', 'Value'], $grouped);
    }

    /**
     * @param  list<array{depth?: int, kind?: string, name?: string, start?: float, duration?: float}>  $spans
     */
    private function timeline(array $spans, string $id): void
    {
        if ($spans === []) {
            note('No spans.');

            return;
        }

        // The JSON keeps every span; people get the first --limit of them.
        $limit = max(1, (int) ($this->option('limit') ?? 25));
        $total = count($spans);
        $spans = array_slice($spans, 0, $limit);

        $rows = [];

        foreach ($spans as $span) {
            $indent = str_repeat('  ', (int) ($span['depth'] ?? 0));

            $rows[] = [
                $this->ms($span['start'] ?? 0),
                $this->ms($span['duration'] ?? 0),
                (string) ($span['kind'] ?? ''),
                $indent.$this->cell($span['name'] ?? '', 80),
            ];
        }

        table(['Start', 'Duration', 'Kind', 'Name'], $rows);

        $page = [
            'offset' => 0,
            'returned' => count($spans),
            'total' => $total,
            'next_offset' => $total > $limit ? $limit : null,
        ];

        $this->paging($page, 'php artisan monocle:rows timeline '.$id);
    }

    /**
     * A section as SectionReader returns it: data for set() sections, paged rows otherwise.
     *
     * @param  array{section?: string, data?: array<string, mixed>, rows?: list<array<string, mixed>>, offset?: int, returned?: int, total?: int, next_offset?: int|null}  $section
     */
    private function section(array $section, string $id): void
    {
        if (isset($section['data'])) {
            $this->dotTable($section['data']);

            return;
        }

        $rows = $section['rows'] ?? [];
        [$shown] = $this->columns($rows, self::MAX_COLUMNS);

        $this->rowsTable(array_map($this->readable(...), $rows), $shown);

        // monocle:show has no --offset; the next page comes from monocle:rows.
        $this->paging($section, 'php artisan monocle:rows '.($section['section'] ?? '').' '.$id);
    }

    /**
     * @param  list<array{key: string, rows: int|null}>  $sections
     */
    private function availableSections(array $sections): void
    {
        $parts = [];

        foreach ($sections as $section) {
            if ($section['rows'] === 0) {
                continue;
            }

            $parts[] = $section['rows'] === null ? $section['key'] : $section['key'].' ('.$section['rows'].')';
        }

        if ($parts === []) {
            return;
        }

        note('Sections (--section=...): '.implode(', ', $parts));
    }

    private function total(mixed $value, ?string $unit): string
    {
        return match ($unit) {
            null => $this->cell($value),
            'ms' => $this->ms($value),
            'bytes' => $this->bytes($value),
            'mb' => $value.' MB',
            'percent' => $value.'%',
            default => $value.' '.$unit,
        };
    }

    private function isEmptyTotal(mixed $value): bool
    {
        return in_array($value, [null, 0, 0.0, '', []], true);
    }

    private function bar(float $percent, int $width = 20): string
    {
        $clamped = max(0, min(100, $percent));

        return str_repeat('█', (int) round($clamped / 100 * $width));
    }

    protected function getArguments(): array
    {
        return [
            new InputArgument('id', InputArgument::OPTIONAL, 'Capture id or "latest"', 'latest'),
        ];
    }

    protected function getOptions(): array
    {
        return [
            new InputOption('section', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sections to include (summary, timeline, queries, ...)'),
            new InputOption('kind', 'k', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Timeline: only these span kinds'),
            new InputOption('search', null, InputOption::VALUE_REQUIRED, 'Free text: only rows and timeline spans where every word appears in some value'),
            new InputOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows per section (default 25)'),
            new InputOption('verbose-origin', null, InputOption::VALUE_NONE, 'Include full origin call chains'),
            ...$this->jsonOptions(),
        ];
    }
}
