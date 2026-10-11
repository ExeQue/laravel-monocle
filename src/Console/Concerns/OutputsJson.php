<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console\Concerns;

use Symfony\Component\Console\Input\InputOption;

/** @internal */
trait OutputsJson
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function outputJson(array $data): int
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

        if (! $this->option('compact')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $this->output->writeln((string) json_encode($data, $flags));

        return isset($data['error']) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<InputOption>
     */
    protected function jsonOptions(): array
    {
        return [
            new InputOption('compact', null, InputOption::VALUE_NONE, 'Single-line JSON output'),
        ];
    }

    /**
     * Collect the given option names into an args array, skipping unset ones.
     *
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    protected function optionArgs(array $names): array
    {
        $args = [];

        foreach ($names as $name) {
            $value = $this->option($name);

            if ($value === null || $value === false || $value === []) {
                continue;
            }

            $args[str_replace('-', '_', $name)] = $value;
        }

        return $args;
    }

    /**
     * Parse repeatable "field:op:value" options into where clauses.
     *
     * @param  list<string>  $clauses
     * @return list<array{field: string, op: string, value: string|null}>
     */
    protected function parseWhere(array $clauses): array
    {
        $where = [];

        foreach ($clauses as $clause) {
            $parts = explode(':', $clause, 3);

            $where[] = [
                'field' => $parts[0],
                'op' => $parts[1] ?? '=',
                'value' => $parts[2] ?? null,
            ];
        }

        return $where;
    }
}
