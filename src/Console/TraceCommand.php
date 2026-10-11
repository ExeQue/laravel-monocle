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

            // A parent outside the trace (pruned, or never captured) makes this a root of its own.
            if (! in_array($parent, $ids, true)) {
                $roots[] = $capture;

                continue;
            }

            $children[$parent][] = $capture;
        }

        $pending = $data['pending'] ?? [];

        foreach ($pending as $job) {
            $children[$job['parent_id']][] = [
                'pending' => true,
                'type' => 'job',
                'name' => $job['job'],
                'id' => $job['job_id'],
            ];
        }

        foreach ($roots as $index => $root) {
            $isLast = $index === array_key_last($roots);

            $this->renderNode($root, $children, $data['id'], '', $isLast, true);
        }

        $this->newLine();
        $this->summary($captures, count($pending));
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, list<array<string, mixed>>>  $children
     */
    private function renderNode(array $node, array $children, string $requested, string $prefix, bool $last, bool $root = false): void
    {
        $branch = $last ? '└─ ' : '├─ ';
        // Below the last child nothing continues, so its children get blank space instead of a line.
        $continuation = $last ? '   ' : '│  ';

        $guide = $root ? '' : $branch;
        $childPrefix = $root ? '' : $prefix.$continuation;

        $this->output->writeln($prefix.$guide.$this->describe($node, $requested));

        $kids = $children[$node['id']] ?? [];

        foreach ($kids as $index => $kid) {
            $isLast = $index === array_key_last($kids);

            $this->renderNode($kid, $children, $requested, $childPrefix, $isLast);
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

        $line = sprintf(
            '<fg=gray>%s</> <options=bold>%s</>  %s  %s  <fg=gray>at +%s</>',
            $type,
            $name,
            $this->status($node),
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
     * @param  array<string, mixed>  $node
     */
    private function status(array $node): string
    {
        $status = (string) ($node['status'] ?? '');

        if ($this->failed($node)) {
            return "<fg=red;options=bold>{$status} FAILED</>";
        }

        return "<fg=green>{$status}</>";
    }

    /**
     * @param  list<array<string, mixed>>  $captures
     */
    private function summary(array $captures, int $pending): void
    {
        $failed = 0;
        // Work adds every duration up; span is the wall time from the root's start to the last finish.
        $work = 0.0;
        $span = 0.0;

        foreach ($captures as $capture) {
            $duration = (float) ($capture['duration'] ?? 0);
            $finishedAt = (float) ($capture['offset_ms'] ?? 0) + $duration;

            if ($this->failed($capture)) {
                $failed++;
            }

            $work += $duration;
            $span = max($span, $finishedAt);
        }

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
