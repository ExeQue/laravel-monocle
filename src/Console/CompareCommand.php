<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Console\Concerns\OutputsJson;
use ExeQue\Monocle\Console\Concerns\RendersForHumans;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

use Symfony\Component\Console\Input\InputArgument;

/** @internal */
final class CompareCommand extends Command
{
    use OutputsJson;
    use RendersForHumans;

    protected $name = 'monocle:compare';

    protected $description = 'Compare two captures by id or label (same output as the monocle-compare MCP tool)';

    public function handle(Viewfinder $viewfinder): int
    {
        return $this->respond($viewfinder->compare([
            'a' => $this->argument('a'),
            'b' => $this->argument('b'),
        ]), $this->render(...));
    }

    protected function getArguments(): array
    {
        return [
            new InputArgument('a', InputArgument::REQUIRED, 'Baseline capture id or label'),
            new InputArgument('b', InputArgument::OPTIONAL, 'Capture id or label to compare against', 'latest'),
        ];
    }

    protected function getOptions(): array
    {
        return $this->jsonOptions();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(array $data): void
    {
        info('A: '.$this->captureLine($data['a']));
        info('B: '.$this->captureLine($data['b']));

        $this->totals($data['totals'] ?? []);
        $this->lines('Phases', $data['phases'] ?? []);
        $this->lines('Categories', $data['categories'] ?? []);
        $this->queries($data['queries'] ?? []);

        foreach (['only_in_a' => 'Only in A', 'only_in_b' => 'Only in B'] as $side => $where) {
            $warnings = $this->prefixed($where, $data['warnings'][$side] ?? []);
            $errors = $this->prefixed($where, $data['errors'][$side] ?? []);

            $this->flags($warnings, $errors);
        }
    }

    /**
     * @param  list<string>  $messages
     * @return list<string>
     */
    private function prefixed(string $prefix, array $messages): array
    {
        return array_map(fn (string $message): string => "{$prefix}: {$message}", $messages);
    }

    /**
     * @param  array{id: string, name: string|null, label: string|null, started_at: string|null}  $capture
     */
    private function captureLine(array $capture): string
    {
        $line = $capture['name'] ?? $capture['id'];

        if ($capture['label'] !== null) {
            $line .= " [{$capture['label']}]";
        }

        return $line.' ('.$this->ago($capture['started_at']).', '.$capture['id'].')';
    }

    /**
     * @param  array<string, array{a: mixed, b: mixed, delta: float|int|null, percent: float|null}>  $totals
     */
    private function totals(array $totals): void
    {
        // CaptureComparer only diffs numeric totals and duration_ms is always one, so rows is never empty.
        $rows = collect($totals)->map(fn (array $total, int|string $name): array => [
            ucfirst(str_replace('_', ' ', (string) $name)),
            $this->amount((string) $name, $total['a']),
            $this->amount((string) $name, $total['b']),
            $this->change((string) $name, $total['delta'], $total['percent']),
        ]);

        table(['Metric', 'A', 'B', 'Change'], $rows->values()->all());
    }

    /**
     * @param  array<string, array{a_ms: float|null, b_ms: float|null, delta_ms: float|null, a_count: int|null, b_count: int|null}>  $lines
     */
    private function lines(string $title, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $rows = collect($lines)->map(fn (array $line, int|string $name): array => [
            $name,
            $line['a_ms'] === null ? '' : $this->ms($line['a_ms']),
            $line['b_ms'] === null ? '' : $this->ms($line['b_ms']),
            $this->change('ms', $line['delta_ms'], null),
            (string) ($line['a_count'] ?? ''),
            (string) ($line['b_count'] ?? ''),
        ]);

        note($title);

        table(['Name', 'A', 'B', 'Change', 'Count A', 'Count B'], $rows->values()->all());
    }

    /**
     * @param  array{only_in_a?: list<array{shape: string, count: int, total_ms: float}>, only_in_b?: list<array{shape: string, count: int, total_ms: float}>, changed?: list<array{shape: string, a_count: int, b_count: int, a_ms: float, b_ms: float}>}  $queries
     */
    private function queries(array $queries): void
    {
        foreach (['only_in_a' => 'Queries only in A', 'only_in_b' => 'Queries only in B'] as $key => $title) {
            if (($queries[$key] ?? []) === []) {
                continue;
            }

            $rows = collect($queries[$key])->map(fn (array $query): array => [
                $this->cell($query['shape'], 90),
                (string) $query['count'],
                $this->ms($query['total_ms']),
            ]);

            note($title);
            table(['Query', 'Count', 'Time'], $rows->all());
        }

        $changed = $queries['changed'] ?? [];

        if ($changed === []) {
            return;
        }

        $rows = collect($changed)->map(fn (array $query): array => [
            $this->cell($query['shape'], 90),
            (string) $query['a_count'],
            (string) $query['b_count'],
            $this->ms($query['a_ms']),
            $this->ms($query['b_ms']),
        ]);

        note('Queries run a different number of times');
        table(['Query', 'Count A', 'Count B', 'Time A', 'Time B'], $rows->all());
    }

    /**
     * Signed delta and percent: red when it got worse, green when better.
     * Higher times and counts are worse, except hit counts and hit rates.
     */
    private function change(string $name, float|int|null $delta, ?float $percent): string
    {
        if ($delta === null) {
            return '';
        }

        $text = $this->sign($delta).$this->amount($name, abs($delta));

        if ($percent !== null) {
            $plus = $percent > 0 ? '+' : '';
            $text .= ' ('.$plus.$percent.'%)';
        }

        if ((float) $delta === 0.0) {
            return $text;
        }

        $worse = $delta > 0;

        if (str_contains($name, 'hit')) {
            $worse = ! $worse;
        }

        if ($worse) {
            return "<fg=red>{$text}</>";
        }

        return "<fg=green>{$text}</>";
    }

    private function sign(float|int $delta): string
    {
        if ($delta > 0) {
            return '+';
        }

        if ($delta < 0) {
            return '-';
        }

        return '';
    }
}
