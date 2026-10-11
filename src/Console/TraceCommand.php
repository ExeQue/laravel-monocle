<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Console\Concerns\OutputsJson;
use ExeQue\Monocle\Console\Concerns\RendersForHumans;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

use Symfony\Component\Console\Input\InputArgument;

/** @internal */
final class TraceCommand extends Command
{
    use OutputsJson;
    use RendersForHumans;

    protected $name = 'monocle:trace';

    protected $description = 'Show every capture in the trace of the given capture (same output as the monocle-trace MCP tool)';

    public function handle(Viewfinder $viewfinder): int
    {
        return $this->respond(
            $viewfinder->trace(['id' => $this->argument('id')]),
            $this->render(...),
        );
    }

    protected function getArguments(): array
    {
        return [
            new InputArgument('id', InputArgument::OPTIONAL, 'Any capture id in the trace or "latest"', 'latest'),
        ];
    }

    protected function getOptions(): array
    {
        return $this->jsonOptions();
    }

    /**
     * The trace as a tree from the root capture down through every job it
     * dispatched, queued jobs without a capture yet included.
     *
     * @param  array{id: string, trace_id: string|null, captures: list<array<string, mixed>>}  $data
     */
    private function render(array $data): void
    {
        $captures = $data['captures'];
        $ids = array_column($captures, 'id');
        $children = [];
        $roots = [];

        foreach ($captures as $capture) {
            $parent = $capture['parent_id'] ?? null;

            if ($parent === null) {
                $roots[] = $capture;

                continue;
            }

            if (! in_array($parent, $ids, true)) {
                $roots[] = $capture;

                continue;
            }

            $children[$parent][] = $capture;
        }

        $pending = 0;

        foreach ($data['pending'] ?? [] as $job) {
            $children[$job['parent_id']][] = ['pending' => true, 'type' => 'job', 'name' => $job['job'], 'id' => $job['job_id']];
            $pending++;
        }

        foreach ($roots as $index => $root) {
            $this->renderNode($root, $children, $data['id'], '', $index === array_key_last($roots), true);
        }

        $this->newLine();
        $this->summary($captures, $pending);
    }


    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, list<array<string, mixed>>>  $children
     */
    private function renderNode(array $node, array $children, string $requested, string $prefix, bool $last, bool $root = false): void
    {
        $guide = $root ? '' : ($last ? '└─ ' : '├─ ');

        $this->output->writeln($prefix.$guide.$this->describe($node, $requested));

        $kids = $children[$node['id']] ?? [];
        $childPrefix = $root ? '' : $prefix.($last ? '   ' : '│  ');

        foreach ($kids as $index => $kid) {
            $this->renderNode($kid, $children, $requested, $childPrefix, $index === array_key_last($kids));
        }
    }

    /**
     * One tree line: type, name, status, duration, start offset from the
     * root, error and warning counts and the id to pass to monocle:show.
     *
     * @param  array<string, mixed>  $node
     */
    private function describe(array $node, string $requested): string
    {
        $type = (string) $node['type'];
        $name = $type === 'job' ? class_basename((string) $node['name']) : (string) $node['name'];

        if ($node['pending'] ?? false) {
            return sprintf('<fg=gray>%s</> %s  <fg=yellow>PENDING, no capture yet</>  <fg=gray>job id %s</>', $type, $name, $node['id']);
        }

        $status = (string) ($node['status'] ?? '');
        $status = $this->failed($node) ? "<fg=red;options=bold>{$status} FAILED</>" : "<fg=green>{$status}</>";

        $line = sprintf(
            '<fg=gray>%s</> <options=bold>%s</>  %s  %s  <fg=gray>at +%s</>',
            $type,
            $name,
            $status,
            $this->ms($node['duration'] ?? null),
            $this->ms($node['offset_ms'] ?? null),
        );

        if ((int) ($node['errors'] ?? 0) > 0) {
            $line .= sprintf('  <fg=red>%d errors</>', $node['errors']);
        }

        if ((int) ($node['warnings'] ?? 0) > 0) {
            $line .= sprintf('  <fg=yellow>%d warnings</>', $node['warnings']);
        }

        $line .= sprintf('  <fg=gray>%s</>', $node['id']);

        if ($node['id'] === $requested) {
            $line .= '  <fg=cyan;options=bold><- requested</>';
        }

        return $line;
    }

    /**
     * @param  list<array<string, mixed>>  $captures
     */
    private function summary(array $captures, int $pending): void
    {
        $failed = count(array_filter($captures, fn (array $capture): bool => $this->failed($capture)));
        $work = array_sum(array_map(fn (array $capture): float => (float) ($capture['duration'] ?? 0), $captures));
        $span = max(array_map(fn (array $capture): float => (float) ($capture['offset_ms'] ?? 0) + (float) ($capture['duration'] ?? 0), $captures));

        $line = sprintf(
            '%d %s, %s of work over %s, %d failed',
            count($captures),
            count($captures) === 1 ? 'capture' : 'captures',
            $this->ms($work),
            $this->ms($span),
            $failed,
        );

        if ($pending > 0) {
            $line .= ", {$pending} pending";
        }

        $line .= '.';

        if ($failed > 0) {
            warning($line);

            return;
        }

        info($line);
    }
}
