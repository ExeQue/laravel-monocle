<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Console\Concerns\OutputsJson;
use ExeQue\Monocle\Console\Concerns\RendersForHumans;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/** @internal */
final class RowsCommand extends Command
{
    use OutputsJson;
    use RendersForHumans;

    /**
     * More scalar columns than this and the rest are left out of the table.
     */
    private const int MAX_COLUMNS = 8;

    protected $name = 'monocle:rows';

    protected $description = 'Paginated, filterable rows of one section (same output as the monocle-rows MCP tool)';

    public function handle(Viewfinder $viewfinder): int
    {
        $where = $this->parseWhere($this->option('where'));

        return $this->respond($viewfinder->rows([
            'id' => $this->argument('id'),
            'section' => $this->argument('section'),
            'where' => $where,
            'fields' => $this->option('field'),
            'kinds' => $this->option('kind'),
            'verbose' => $this->option('verbose-origin'),
            ...$this->optionArgs(['offset', 'limit', 'sort', 'dir', 'search']),
        ]), fn (array $data) => $this->render($data, $where));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{field: string, op: string, value: string|null}>  $where
     */
    private function render(array $data, array $where): void
    {
        $section = (string) ($data['section'] ?? $this->argument('section'));

        info(Str::headline($section).' of '.$data['id']);
        $this->filterLine($where, $data);

        if (array_key_exists('data', $data)) {
            $this->dotTable($data['data']);

            return;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $data['rows'] ?? [];

        // A single row reads better as key/value pairs than as one wide table line.
        if (count($rows) === 1) {
            $this->dotTable($this->readable($rows[0]));
            $this->paging($data);

            return;
        }

        $this->rowsTable(array_map($this->readable(...), $rows), $this->pickColumns($rows));
        $this->paging($data);
    }

    /**
     * The applied where clauses and sort as one line, with how many rows matched.
     *
     * @param  list<array{field: string, op: string, value: string|null}>  $where
     * @param  array<string, mixed>  $data
     */
    private function filterLine(array $where, array $data): void
    {
        $parts = [];

        foreach ($where as $clause) {
            $parts[] = trim("{$clause['field']} {$clause['op']} {$clause['value']}");
        }

        $search = $this->option('search');

        if ($search !== null) {
            $parts[] = 'containing "'.$search.'"';
        }

        $sort = $this->option('sort');

        if ($sort !== null) {
            $direction = $this->option('dir') ?? 'asc';
            $parts[] = 'sorted by '.$sort.' '.$direction;
        }

        if ($parts === []) {
            return;
        }

        $line = 'Filter: '.implode(', ', $parts);

        if (isset($data['total'], $data['filtered_from'])) {
            $line .= " ({$data['total']} of {$data['filtered_from']} rows match)";
        }

        note($line);
    }

    /**
     * Table columns: the --field ones, or the scalar columns that hold a
     * value in at least one row, without the origin, capped at MAX_COLUMNS.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function pickColumns(array $rows): array
    {
        if ($this->option('field') !== []) {
            return $this->option('field');
        }

        [$shown, $hidden] = $this->columns($rows, self::MAX_COLUMNS);

        if ($hidden !== []) {
            note('Not shown: '.implode(', ', $hidden).'. Pick columns with --field.');
        }

        return $shown;
    }

    protected function getArguments(): array
    {
        return [
            new InputArgument('section', InputArgument::REQUIRED, 'Section name (queries, cache, timeline, ...)'),
            new InputArgument('id', InputArgument::OPTIONAL, 'Capture id or "latest"', 'latest'),
        ];
    }

    protected function getOptions(): array
    {
        return [
            new InputOption('where', 'w', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter as field:op:value, e.g. duration:>:20'),
            new InputOption('field', 'f', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only output these fields'),
            new InputOption('kind', 'k', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Timeline: only these span kinds'),
            new InputOption('search', null, InputOption::VALUE_REQUIRED, 'Free text: only rows where every word appears in some value'),
            new InputOption('sort', null, InputOption::VALUE_REQUIRED, 'Sort field'),
            new InputOption('dir', null, InputOption::VALUE_REQUIRED, 'asc|desc'),
            new InputOption('offset', null, InputOption::VALUE_REQUIRED, 'Skip this many rows'),
            new InputOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows per page (default 50)'),
            new InputOption('verbose-origin', null, InputOption::VALUE_NONE, 'Include full origin call chains'),
            ...$this->jsonOptions(),
        ];
    }
}
