<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Console\Concerns\OutputsJson;
use ExeQue\Monocle\Console\Concerns\RendersForHumans;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Console\Command;

use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

use Symfony\Component\Console\Input\InputOption;

/** @internal */
final class ListCommand extends Command
{
    use OutputsJson;
    use RendersForHumans;

    protected $name = 'monocle:list';

    protected $description = 'List recorded captures newest first (same output as the monocle-list MCP tool)';

    public function handle(Viewfinder $viewfinder): int
    {
        $data = $viewfinder->list([
            'has' => $this->option('has'),
            'exclude' => $this->option('exclude'),
            ...$this->optionArgs(['type', 'uri', 'status', 'min-duration-ms', 'since', 'label', 'has-errors', 'offset', 'limit', 'search']),
        ]);

        return $this->respond($data, fn (array $data) => $this->renderForHumans($data));
    }

    /**
     * @param  array{rows?: list<array<string, mixed>>}  $data
     */
    private function renderForHumans(array $data): void
    {
        $rows = $data['rows'] ?? [];

        if ($rows === []) {
            note('No captures found.');

            return;
        }

        table(
            ['When', 'Type', 'Name', 'Status', 'Duration', 'Queries', 'Flags', 'ID'],
            array_map(fn (array $row): array => $this->row($row), $rows),
        );

        $this->paging($data);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function row(array $row): array
    {
        $name = (string) $row['name'];

        foreach ((array) ($row['tags'] ?? []) as $tag) {
            $name .= ' ['.$tag['label'].']';
        }

        if ($row['label'] !== null) {
            $name .= ' ('.$row['label'].')';
        }

        $flags = [];

        if ($row['errors'] > 0) {
            $flags[] = '<fg=red;options=bold>'.$row['errors'].' error'.($row['errors'] === 1 ? '' : 's').'</>';
        }

        if ($row['warnings'] > 0) {
            $flags[] = '<fg=yellow>'.$row['warnings'].' warning'.($row['warnings'] === 1 ? '' : 's').'</>';
        }

        return [
            $this->ago($row['started_at']),
            (string) $row['type'],
            ($row['has_errors'] ? '<fg=red>✗</> ' : '').$this->cell($name, 70),
            (string) $row['status'],
            $this->ms($row['duration']),
            (string) ($row['counts']['queries'] ?? 0),
            implode(' ', $flags),
            (string) $row['id'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            new InputOption('search', null, InputOption::VALUE_REQUIRED, 'Free text: every word in the name, route, type, status, label or a tag'),
            new InputOption('type', null, InputOption::VALUE_REQUIRED, 'request|job|command|mcp'),
            new InputOption('uri', null, InputOption::VALUE_REQUIRED, 'Substring of the capture name'),
            new InputOption('status', null, InputOption::VALUE_REQUIRED, 'Exact HTTP status'),
            new InputOption('min-duration-ms', null, InputOption::VALUE_REQUIRED, 'Only captures at least this slow'),
            new InputOption('since', null, InputOption::VALUE_REQUIRED, 'Unix timestamp, ISO date or relative (15m, 2h)'),
            new InputOption('label', null, InputOption::VALUE_REQUIRED, 'Only captures with this label'),
            new InputOption('has-errors', null, InputOption::VALUE_NONE, 'Only captures with errors'),
            new InputOption('has', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only captures where this section recorded rows (repeatable)'),
            new InputOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Leave out captures whose name contains this (repeatable)'),
            new InputOption('offset', null, InputOption::VALUE_REQUIRED, 'Skip this many rows'),
            new InputOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows per page (default 20)'),
            ...$this->jsonOptions(),
        ];
    }
}
