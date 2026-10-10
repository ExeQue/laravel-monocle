<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install;

use RuntimeException;

/**
 * Merges one server into an MCP client config file, leaving everything else
 * in the file untouched.
 */
final class McpConfigWriter
{
    /**
     * @param  array{command: string, args: list<string>}  $server
     * @return 'created'|'updated'|'unchanged'
     */
    public function write(string $file, string $name, array $server, bool $dryRun = false): string
    {
        $exists = is_file($file);
        $original = $exists ? (string) file_get_contents($file) : '';
        $config = $exists ? $this->read($file) : [];

        $current = $config['mcpServers'][$name] ?? null;
        $config['mcpServers'] = ($config['mcpServers'] ?? []);
        $config['mcpServers'][$name] = $server;

        if ($current === $server) {
            return 'unchanged';
        }

        if (! $dryRun) {
            $directory = dirname($file);

            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new RuntimeException("Could not create [{$directory}].");
            }

            $json = $this->reindent(
                json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $this->detectIndent($original),
            );

            if (file_put_contents($file, $json."\n") === false) {
                throw new RuntimeException("Could not write [{$file}].");
            }
        }

        return $exists ? 'updated' : 'created';
    }

    /**
     * Keep the indentation the file already uses so the diff only shows the
     * server that was added.
     */
    private function detectIndent(string $contents): int
    {
        return preg_match('/^( +)\S/m', $contents, $matches) === 1 ? strlen($matches[1]) : 4;
    }

    private function reindent(string $json, int $indent): string
    {
        if ($indent === 4) {
            return $json;
        }

        return (string) preg_replace_callback(
            '/^( +)/m',
            fn (array $matches): string => str_repeat(' ', intdiv(strlen($matches[1]), 4) * $indent),
            $json,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $file): array
    {
        $contents = file_get_contents($file);
        $decoded = is_string($contents) && trim($contents) !== '' ? json_decode($contents, true) : [];

        if (! is_array($decoded)) {
            throw new RuntimeException("[{$file}] does not contain valid JSON.");
        }

        return $decoded;
    }
}
