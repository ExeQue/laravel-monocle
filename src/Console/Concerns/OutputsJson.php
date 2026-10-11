<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console\Concerns;

use Closure;
use Laravel\AgentDetector\AgentDetector;

use function Laravel\Prompts\error;

use Symfony\Component\Console\Input\InputOption;

/**
 * Agents (detected, or asked for with --json or --agent) get the same JSON
 * the MCP tools return; people get the output rendered with Laravel Prompts.
 *
 * @internal
 */
trait OutputsJson
{
    /**
     * @param  array<string, mixed>  $data
     * @param  Closure(array<string, mixed>): void  $forHumans
     */
    protected function respond(array $data, Closure $forHumans): int
    {
        if ($this->wantsJson()) {
            return $this->outputJson($data);
        }

        if (isset($data['error'])) {
            error((string) $data['error']);

            return self::FAILURE;
        }

        $forHumans($data);

        return self::SUCCESS;
    }

    protected function wantsJson(): bool
    {
        if ($this->option('json') || $this->option('agent') || $this->option('compact')) {
            return true;
        }

        // Like laravel/pao: the test suite never counts as an agent.
        if ($this->laravel->runningUnitTests()) {
            return false;
        }

        return AgentDetector::detect()->isAgent;
    }

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
            new InputOption('json', null, InputOption::VALUE_NONE, 'JSON output, as the MCP tools return it (the default when an AI agent runs the command)'),
            new InputOption('agent', null, InputOption::VALUE_NONE, 'Same as --json'),
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
