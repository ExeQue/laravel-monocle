<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install;

/**
 * How the MCP client should start `artisan mcp:start monocle` on this machine.
 *
 * @internal
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
    public static function detect(string $basePath, array $env = [], string $phpBinary = PHP_BINARY): self
    {
        $env = $env === [] ? getenv() : $env;
        $base = rtrim($basePath, '/').'/';

        if (self::usesLerd($base, $env)) {
            return self::Lerd;
        }

        if (self::usesSail($base, $env)) {
            return self::Sail;
        }

        if (self::herdInstalled($env, $phpBinary)) {
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
            self::Lerd => ['command' => 'lerd', 'args' => ['artisan', 'mcp:start', $server]],
            self::Herd => ['command' => 'herd', 'args' => ['php', 'artisan', 'mcp:start', $server]],
            self::Php => ['command' => PHP_BINARY, 'args' => [rtrim($basePath, '/').'/artisan', 'mcp:start', $server]],
        };
    }

    /**
     * How to run artisan on this platform, for instructions shown to the user.
     */
    public function artisan(): string
    {
        return match ($this) {
            self::Sail => 'vendor/bin/sail artisan',
            self::Lerd => 'lerd artisan',
            self::Herd => 'herd php artisan',
            self::Php => 'php artisan',
        };
    }

    /**
     * @param  array<string, string|false>  $env
     */
    private static function usesLerd(string $base, array $env): bool
    {
        if (self::anyExists($base, ['.lerd.yaml', '.lerd'])) {
            return true;
        }

        return self::envStartsWith($env, 'LERD_');
    }

    /**
     * Inside a Sail container LARAVEL_SAIL is set; on the host the project
     * has the Sail script next to a compose file.
     *
     * @param  array<string, string|false>  $env
     */
    private static function usesSail(string $base, array $env): bool
    {
        if (($env['LARAVEL_SAIL'] ?? null) === '1') {
            return true;
        }

        if (! file_exists($base.'vendor/bin/sail')) {
            return false;
        }

        return self::anyExists($base, ['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml']);
    }

    /**
     * @param  list<string>  $files
     */
    private static function anyExists(string $base, array $files): bool
    {
        foreach ($files as $file) {
            if (file_exists($base.$file)) {
                return true;
            }
        }

        return false;
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
    private static function herdInstalled(array $env, string $phpBinary): bool
    {
        // macOS: ~/Library/Application Support/Herd/bin, Windows: %USERPROFILE%\.config\herd\bin
        if (stripos(str_replace('\\', '/', $phpBinary), '/herd/') !== false) {
            return true;
        }

        if (file_exists('/Applications/Herd.app/Contents/MacOS/Herd')) {
            return true;
        }

        $home = $env['HOME'] ?? $env['USERPROFILE'] ?? null;

        return is_string($home) && is_dir(str_replace('\\', '/', $home).'/.config/herd');
    }
}
