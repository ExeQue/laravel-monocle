<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install;

use Illuminate\Contracts\Foundation\Application;
use Laravel\Boost\BoostManager;
use Laravel\Boost\Contracts\SupportsMcp;
use Laravel\Boost\Install\Agents\Agent;
use Laravel\Boost\Support\Config;
use Throwable;

/**
 * Registers the MCP server with every agent Laravel Boost is set up for
 * (boost.json), using Boost's own agent definitions so each agent gets its
 * own config file and format.
 *
 * @internal
 */
final class BoostAgents
{
    public function __construct(
        private readonly Application $app
    ) {
    }

    public function detected(): bool
    {
        return is_file($this->app->basePath('boost.json'));
    }

    /**
     * @return list<string>
     */
    public function agents(): array
    {
        if (! $this->detected()) {
            return [];
        }

        return array_values(array_filter(new Config()->getAgents(), 'is_string'));
    }

    /**
     * @param  array{command: string, args: list<string>}  $command
     * @return array<string, string> agent name => config path written, or the reason it was skipped
     */
    public function install(string $server, array $command, bool $dryRun = false): array
    {
        $available = $this->app->make(BoostManager::class)->getAgents();
        $results = [];

        foreach ($this->agents() as $name) {
            $class = $available[$name] ?? null;

            if ($class === null) {
                $results[$name] = 'skipped: unknown agent';

                continue;
            }

            /** @var Agent $agent */
            $agent = $this->app->make($class);

            if (! $agent instanceof SupportsMcp) {
                $results[$name] = 'skipped: no MCP support';

                continue;
            }

            // Not every Boost agent exposes its config path; those report "registered" instead.
            $path = method_exists($agent, 'mcpConfigPath') ? (string) $agent->mcpConfigPath() : '';

            if ($dryRun) {
                $results[$name] = $path === '' ? 'would be registered' : "would write {$path}";

                continue;
            }

            $results[$name] = $this->inBasePath(function () use ($agent, $server, $command, $path): string {
                try {
                    $installed = $agent->installMcp($server, $command['command'], $command['args']);
                } catch (Throwable $exception) {
                    return 'failed: '.$exception->getMessage();
                }

                if (! $installed) {
                    return 'failed';
                }

                return $path ?: 'registered';
            });
        }

        return $results;
    }

    /**
     * Boost writes agent config files relative to the working directory.
     *
     * @param  callable(): string  $callback
     */
    private function inBasePath(callable $callback): string
    {
        $previous = getcwd();
        chdir($this->app->basePath());

        try {
            return $callback();
        } finally {
            if ($previous !== false) {
                chdir($previous);
            }
        }
    }
}
