<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install;

use ExeQue\Monocle\Install\Concerns\WritesJson;
use RuntimeException;

/**
 * Merges one server into an MCP client config file, leaving everything else
 * in the file untouched.
 *
 * @internal
 */
final class McpConfigWriter
{
    use WritesJson;

    /**
     * @param  array{command: string, args: list<string>}  $server
     * @return 'created'|'updated'|'unchanged'
     */
    public function write(string $file, string $name, array $server, bool $dryRun = false): string
    {
        $exists = is_file($file);
        $config = $this->readJson($file);

        if ($config === null) {
            throw new RuntimeException("[{$file}] does not contain valid JSON.");
        }

        $current = $config['mcpServers'][$name] ?? null;
        $config['mcpServers'] = ($config['mcpServers'] ?? []);
        $config['mcpServers'][$name] = $server;

        if ($current === $server) {
            return 'unchanged';
        }

        if (! $dryRun) {
            $this->writeJson($file, $config);
        }

        return $exists ? 'updated' : 'created';
    }
}
