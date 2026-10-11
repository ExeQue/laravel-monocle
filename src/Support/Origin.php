<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Support;

use Closure;
use ExeQue\Monocle\Contracts\Lens;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Finds the user-land call site that triggered a recorded operation and a
 * short call chain above it. Vendor and Monocle frames are skipped; compiled
 * Blade files are mapped back to their .blade.php source. The library method
 * the user-land code called is added as "via"; when no user-land code is
 * involved at all, the library call that did the work (outside the database,
 * cache and event plumbing) is reported instead, marked as vendor.
 *
 * @internal
 */
final class Origin
{
    /** @var array<string, bool> Classes already checked for being a lens. */
    private static array $lenses = [];

    /** @var array<string, bool> Files already classified, as vendor, plumbing or Monocle's own. */
    private static array $vendorFiles = [];

    /** @var list<string>|null The include_vendor setting $vendorFiles was built for. */
    private static ?array $vendorRules = null;

    /** @var array<string, bool> */
    private static array $plumbingFiles = [];

    /** @var array<string, bool> */
    private static array $ownFiles = [];

    private static ?string $ownDirectory = null;

    /** @var array<string, array{file: string, line: int|null, call: string}> Relation methods already looked up. */
    private static array $relations = [];

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
        $live = $trace === null;
        $trace ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60);
        $kept = [];
        $library = null;
        $called = null;

        foreach ($trace as $index => $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null) {
                continue;
            }

            $class = (string) ($trace[$index + 1]['class'] ?? '');

            if (str_starts_with($class, 'ExeQue\\Monocle\\') || self::isOwn($file)) {
                continue;
            }

            // An application's own lens records; the origin is the code that made it record.
            if ($class !== '' && (self::$lenses[$class] ??= is_a($class, Lens::class, true))) {
                continue;
            }

            $call = self::callOf($trace[$index + 1] ?? null);

            if (self::isVendor($file, $basePath, $includeVendor)) {
                if ($kept === []) {
                    $macro = self::isMagic($call) ? self::macro($trace, $index, $basePath) : null;

                    // A magic method says nothing; name the macro behind it, or keep the real method.
                    if ($macro !== null) {
                        $called = $macro;
                    } elseif ($called === null || ! self::isMagic($call)) {
                        $called = ['file' => self::relative($file, $basePath), 'line' => $frame['line'] ?? null, 'call' => $call];
                    }

                    if ($library === null && ! self::isPlumbing($file)) {
                        $library = [...$called, 'frames' => [], 'vendor' => true];
                    }
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

        if ($called !== null && str_ends_with((string) $called['call'], '->__get')) {
            $called = self::relation($live ? self::traceWithArguments($trace) : $trace, $basePath) ?? $called;
        }

        if ($called !== null) {
            $origin['via'] = $called;
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
        // The same files come up on every row; classify each once (per include_vendor setting).
        if (self::$vendorRules !== $includeVendor) {
            self::$vendorRules = $includeVendor;
            self::$vendorFiles = [];
        }

        return self::$vendorFiles[$file] ??= self::classifyVendor($file, $includeVendor);
    }

    /**
     * @param  list<string>  $includeVendor
     */
    private static function classifyVendor(string $file, array $includeVendor): bool
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

    /**
     * The macro a __call/__callStatic frame ran: the registered macro whose
     * closure contains the line the next frame in was called from.
     *
     * @param  list<array<string, mixed>>  $trace
     * @return array{file: string, line: int|null, call: string}|null
     */
    private static function macro(array $trace, int $index, string $basePath): ?array
    {
        $caller = $trace[$index + 1] ?? [];
        $class = $caller['class'] ?? null;
        $inside = $trace[$index - 1] ?? null;

        if (! in_array($caller['function'] ?? null, ['__call', '__callStatic'], true) || ! is_string($class) || $inside === null || ! isset($inside['file'], $inside['line'])) {
            return null;
        }

        try {
            $property = new ReflectionProperty($class, 'macros');
            $macros = $property->isStatic() ? (array) $property->getValue() : [];
        } catch (ReflectionException) {
            return null;
        }

        foreach ($macros as $name => $macro) {
            if (! $macro instanceof Closure) {
                continue;
            }

            $function = new ReflectionFunction($macro);

            if ($function->getFileName() === $inside['file'] && $inside['line'] >= $function->getStartLine() && $inside['line'] <= $function->getEndLine()) {
                return [
                    'file' => self::relative((string) $function->getFileName(), $basePath),
                    'line' => $function->getStartLine() ?: null,
                    'call' => $class.($caller['type'] ?? '->').$name,
                ];
            }
        }

        return null;
    }

    /**
     * A relation loaded by reading a model property: the relation method,
     * found in the arguments of Eloquent's getRelationshipFromMethod() call.
     *
     * @param  list<array<string, mixed>>  $trace  a backtrace with arguments
     * @return array{file: string, line: int|null, call: string}|null
     */
    /**
     * The relation's name is an argument, so the trace is taken again with
     * arguments, but only as deep as the frame that holds it.
     *
     * @param  list<array<string, mixed>>  $trace
     * @return list<array<string, mixed>>
     */
    private static function traceWithArguments(array $trace): array
    {
        foreach ($trace as $index => $frame) {
            if (($frame['function'] ?? null) === 'getRelationshipFromMethod') {
                // One more frame: this method sits between capture() and the trace it took.
                return array_slice(debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, $index + 2), 1);
            }
        }

        return [];
    }

    /**
     * @return array{file: string, line: int|null, call: string}
     */
    private static function relationDefinition(string $class, string $method, string $basePath): array
    {
        $reflection = new ReflectionMethod($class, $method);

        return [
            'file' => self::relative((string) $reflection->getFileName(), $basePath),
            'line' => $reflection->getStartLine() ?: null,
            'call' => $class.'->'.$method,
        ];
    }

    private static function relation(array $trace, string $basePath): ?array
    {
        foreach ($trace as $frame) {
            $method = $frame['args'][0] ?? null;

            if (($frame['function'] ?? null) !== 'getRelationshipFromMethod' || ! is_string($method)) {
                continue;
            }

            $class = isset($frame['object']) ? $frame['object']::class : ($frame['class'] ?? null);

            if (! is_string($class) || ! method_exists($class, $method)) {
                return null;
            }

            return self::$relations[$class.'->'.$method] ??= self::relationDefinition($class, $method, $basePath);
        }

        return null;
    }

    private static function isMagic(?string $call): bool
    {
        return $call === null || preg_match('/(?:->|::)(?:__call|__callStatic|__invoke)$|\{closure/', $call) === 1;
    }

    private static function isPlumbing(string $file): bool
    {
        return self::$plumbingFiles[$file] ??= self::classifyPlumbing($file);
    }

    private static function classifyPlumbing(string $file): bool
    {
        $file = str_replace('\\', '/', $file);

        if (str_contains($file, '/exeque/laravel-monocle/') || self::isOwn($file)) {
            return true;
        }

        foreach (self::PLUMBING as $path) {
            if (str_contains($file, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Monocle's own source, wherever the package is installed (vendor/ or a
     * path repository next to the project).
     */
    private static function isOwn(string $file): bool
    {
        self::$ownDirectory ??= str_replace('\\', '/', dirname(__DIR__)).'/';

        return self::$ownFiles[$file] ??= str_starts_with(str_replace('\\', '/', $file), self::$ownDirectory);
    }

    /**
     * Whether the operation came from library code alone, with no code of the
     * application on the way.
     *
     * @param  array<string, mixed>|null  $origin
     */
    public static function fromLibrary(?array $origin): bool
    {
        return ($origin['vendor'] ?? false) === true;
    }

    /**
     * "file:line via Class->method" for warnings.
     *
     * @param  array<string, mixed>|null  $origin
     */
    public static function describe(?array $origin): ?string
    {
        if ($origin === null || ! isset($origin['file'])) {
            return null;
        }

        $where = $origin['file'].(isset($origin['line']) ? ':'.$origin['line'] : '');
        $via = is_array($origin['via'] ?? null) ? ($origin['via']['call'] ?? $origin['via']['file'] ?? null) : null;

        return $via === null ? $where : $where.' via '.$via;
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
