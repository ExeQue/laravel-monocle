<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Support;

/**
 * Finds the user-land call site that triggered a recorded operation and a
 * short call chain above it. Vendor and Monocle frames are skipped; compiled
 * Blade files are mapped back to their .blade.php source. The library call
 * that did the work on the way there (outside the database, cache and event
 * plumbing) is added as "via"; when no user-land code is involved at all, that
 * library call is reported instead, marked as vendor.
 */
final class Origin
{
    /** Library code that only passes the operation along. */
    private const array PLUMBING = [
        '/laravel/framework/src/Illuminate/Database/',
        '/laravel/framework/src/Illuminate/Cache/',
        '/laravel/framework/src/Illuminate/Redis/',
        '/laravel/framework/src/Illuminate/Events/',
        '/laravel/framework/src/Illuminate/Support/Facades/',
        '/laravel/framework/src/Illuminate/Container/',
    ];

    /** @var array<string, string|null> */
    private static array $compiledViewSources = [];

    /**
     * @param  list<string>  $includeVendor
     * @param  list<array<string, mixed>>|null  $trace  the backtrace to read; the current one when null
     * @return array{file: string|null, line: int|null, call: string|null, frames: list<array{file: string, line: int|null, call: string|null}>, vendor?: true, via?: array{file: string, line: int|null, call: string|null}}|null
     */
    public static function capture(string $basePath, int $frames = 5, array $includeVendor = [], ?array $trace = null): ?array
    {
        $trace ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60);
        $kept = [];
        $library = null;

        foreach ($trace as $index => $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null) {
                continue;
            }

            $call = self::callOf($trace[$index + 1] ?? null);

            if (str_starts_with((string) ($trace[$index + 1]['class'] ?? ''), 'ExeQue\\Monocle\\')) {
                continue;
            }

            if (self::isVendor($file, $basePath, $includeVendor)) {
                if ($kept === [] && $library === null && ! self::isPlumbing($file)) {
                    $library = ['file' => self::relative($file, $basePath), 'line' => $frame['line'] ?? null, 'call' => $call, 'frames' => [], 'vendor' => true];
                }

                continue;
            }

            $line = $frame['line'] ?? null;

            if (str_contains($file, 'storage/framework/views')) {
                $source = self::compiledViewSource($file);

                if ($source === null) {
                    continue;
                }

                $file = $source;
                $line = null;
            }

            $kept[] = [
                'file' => self::relative($file, $basePath),
                'line' => $line,
                'call' => $call,
            ];

            if (count($kept) > $frames) {
                break;
            }
        }

        if ($kept === []) {
            return $library;
        }

        $first = array_shift($kept);
        $origin = [
            'file' => $first['file'],
            'line' => $first['line'],
            'call' => $first['call'],
            'frames' => $kept,
        ];

        if ($library !== null) {
            $origin['via'] = ['file' => $library['file'], 'line' => $library['line'], 'call' => $library['call']];
        }

        return $origin;
    }

    /**
     * @param  array<string, mixed>|null  $frame
     */
    private static function callOf(?array $frame): ?string
    {
        if ($frame === null) {
            return null;
        }

        $function = $frame['function'] ?? null;

        if ($function === null) {
            return null;
        }

        if (isset($frame['class'])) {
            return $frame['class'].($frame['type'] ?? '::').$function;
        }

        return $function;
    }

    /**
     * @param  list<string>  $includeVendor
     */
    private static function isVendor(string $file, string $basePath, array $includeVendor): bool
    {
        if (! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
            return false;
        }

        foreach ($includeVendor as $prefix) {
            if (str_contains($file, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private static function isPlumbing(string $file): bool
    {
        $file = str_replace('\\', '/', $file);

        if (str_contains($file, '/exeque/laravel-monocle/')) {
            return true;
        }

        foreach (self::PLUMBING as $path) {
            if (str_contains($file, $path)) {
                return true;
            }
        }

        return false;
    }

    private static function relative(string $file, string $basePath): string
    {
        $basePath = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (str_starts_with($file, $basePath)) {
            return substr($file, strlen($basePath));
        }

        return $file;
    }

    private static function compiledViewSource(string $compiled): ?string
    {
        if (array_key_exists($compiled, self::$compiledViewSources)) {
            return self::$compiledViewSources[$compiled];
        }

        $source = null;
        $contents = @file_get_contents($compiled, false, null, max(0, (int) @filesize($compiled) - 2048));

        if (is_string($contents) && preg_match('#/\*\*PATH (.+?) ENDPATH\*\*/#', $contents, $matches) === 1) {
            $source = $matches[1];
        }

        return self::$compiledViewSources[$compiled] = $source;
    }
}
