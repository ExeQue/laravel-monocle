<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install;

/**
 * How the MCP client should start `artisan mcp:start lens` on this machine.
 */
enum Platform: string
{
    case Sail = 'sail';
    case Lerd = 'lerd';
    case Herd = 'herd';
    case Php = 'php';

    /**
     * First explicit project signal wins (.lerd.yaml, Sail compose file), then
     * environment heuristics, then plain PHP.
     *
     * @param  array<string, string|false>  $env
     */
    public static function detect(string $basePath, array $env = []): self
    {
        $env = $env === [] ? getenv() : $env;
        $has = fn (string $file): bool => file_exists(rtrim($basePath, '/') . '/' . $file);

        if ($has('.lerd.yaml') || $has('.lerd') || self::envStartsWith($env, 'LERD_')) {
            return self::Lerd;
        }

        $compose = $has('compose.yaml') || $has('compose.yml') || $has('docker-compose.yml') || $has('docker-compose.yaml');

        if (($has('vendor/bin/sail') && $compose) || ($env['LARAVEL_SAIL'] ?? null) === '1') {
            return self::Sail;
        }

        if (self::herdInstalled($env)) {
            return self::Herd;
        }

        return self::Php;
    }

    /**
     * @return array{command: string, args: list<string>}
     */
    public function mcpCommand(string $basePath, string $server = 'monocle'): array
    {
        return match ($this) {
            self::Sail => ['command' => 'vendor/bin/sail', 'args' => ['artisan', 'mcp:start', $server]],
            self::Lerd => ['command' => 'lerd', 'args' => ['php', 'artisan', 'mcp:start', $server]],
            self::Herd => ['command' => 'herd', 'args' => ['php', 'artisan', 'mcp:start', $server]],
            self::Php => ['command' => PHP_BINARY, 'args' => [rtrim($basePath, '/') . '/artisan', 'mcp:start', $server]],
        };
    }

    /**
     * @param  array<string, string|false>  $env
     */
    private static function envStartsWith(array $env, string $prefix): bool
    {
        foreach (array_keys($env) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string|false>  $env
     */
    private static function herdInstalled(array $env): bool
    {
        if (str_contains(PHP_BINARY, 'Herd')) {
            return true;
        }

        if (file_exists('/Applications/Herd.app/Contents/MacOS/Herd')) {
            return true;
        }

        $home = $env['HOME'] ?? $env['USERPROFILE'] ?? null;

        return is_string($home) && is_dir(str_replace('\\', '/', $home) . '/.config/herd');
    }
}
