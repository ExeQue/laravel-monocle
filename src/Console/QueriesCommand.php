<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Console\Concerns\OutputsJson;
use ExeQue\Monocle\Console\Concerns\RendersForHumans;
use ExeQue\Monocle\Lenses\DatabaseLens;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/** @internal */
final class QueriesCommand extends Command
{
    use OutputsJson;
    use RendersForHumans;

    protected $name = 'monocle:queries';

    protected $description = 'Aggregate the queries of a capture by shape, caller, table or transaction (same output as the monocle-queries MCP tool)';

    public function handle(Viewfinder $viewfinder): int
    {
        return $this->respond($viewfinder->queries([
            'id' => $this->argument('id'),
            ...$this->optionArgs(['group-by', 'sort', 'offset', 'limit']),
        ]), $this->render(...));
    }

    /**
     * @param  array{id: string, group_by: string, rows: list<array{key: string, count: int, total_ms: float, avg_ms: float, max_ms: float, example: string|null, origin: string|null, rows: list<int|null>}>}  $data
     */
    private function render(array $data): void
    {
        info(sprintf('Queries of %s, grouped by %s, sorted by %s', $data['id'], $data['group_by'], $this->option('sort')));

        if ($data['rows'] === []) {
            note('No queries.');

            return;
        }

        /** @var array{slow_ms?: int|float, n_plus_one_threshold?: int} $options */
        $options = (array) (config('monocle.lenses')[DatabaseLens::class] ?? []);
        $slowMs = (float) ($options['slow_ms'] ?? 100);
        $repeats = $this->nPlusOneThreshold($data['group_by'], $options);

        $rows = [];
        $warnings = [];

        foreach ($data['rows'] as $group) {
            $key = $data['group_by'] === 'caller' ? $this->shortOrigin($group['key']) : $group['key'];
            $flags = [];

            if ($group['count'] >= $repeats) {
                $flags[] = 'N+1?';
                $warnings[] = sprintf('Ran %d times, likely N+1: %s', $group['count'], $this->cell($key, 80));
            }

            if ($group['max_ms'] >= $slowMs) {
                $flags[] = 'slow';
                $warnings[] = sprintf('Slow, up to %s: %s', $this->ms($group['max_ms']), $this->cell($key, 80));
            }

            $affectedRows = array_filter($group['rows'], fn (?int $count): bool => $count !== null);

            $rows[] = [
                (string) $group['count'],
                $this->ms($group['total_ms']),
                $this->ms($group['avg_ms']),
                $this->ms($group['max_ms']),
                $this->cell(implode(',', $affectedRows), 12),
                $this->cell($key, 50),
                $this->cell($this->shortOrigin($group['origin']), 50),
                implode(' ', $flags),
            ];
        }

        table(['Count', 'Total', 'Avg', 'Max', 'Rows', Str::headline($data['group_by']), 'Origin', 'Flags'], $rows);

        $this->paging($data);
        $this->flags($warnings, []);

        $top = $data['rows'][0];

        if ($top['example'] !== null) {
            note('Example of the top group'.PHP_EOL.$this->cell($top['example'], 500));
        }
    }

    /**
     * How often one group has to run before it is flagged as a likely N+1.
     *
     * @param  array{n_plus_one_threshold?: int}  $options
     */
    private function nPlusOneThreshold(string $groupBy, array $options): int
    {
        // Many queries per table or transaction is normal; only a repeated shape or caller hints at N+1.
        if (! in_array($groupBy, ['shape', 'caller'], true)) {
            return PHP_INT_MAX;
        }

        return (int) ($options['n_plus_one_threshold'] ?? 3);
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
            new InputOption('group-by', 'g', InputOption::VALUE_REQUIRED, 'shape|caller|table|transaction', 'shape'),
            new InputOption('sort', null, InputOption::VALUE_REQUIRED, 'total_ms|count|max_ms|avg_ms', 'total_ms'),
            new InputOption('offset', null, InputOption::VALUE_REQUIRED, 'Skip this many groups'),
            new InputOption('limit', null, InputOption::VALUE_REQUIRED, 'Groups per page (default 25)'),
            ...$this->jsonOptions(),
        ];
    }
}
