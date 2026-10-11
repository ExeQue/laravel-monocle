<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Support;

use Throwable;

/**
 * A throwable as it is recorded: its class, message and where it was thrown
 * (relative to the application), plus the exceptions it wraps, outermost
 * first.
 *
 * @internal
 */
final class ThrowableChain
{
    /** Previous exceptions recorded at most. */
    private const int DEPTH = 10;

    /**
     * @param  (callable(string): string)|null  $file  turns an absolute file into the path to record; relative to base_path() by default
     * @return array{class: class-string<Throwable>, message: string, file: string, previous: list<array{class: class-string<Throwable>, message: string, file: string}>}
     */
    public static function describe(Throwable $throwable, ?callable $file = null): array
    {
        $file ??= self::relative(...);
        $previous = [];

        for ($inner = $throwable->getPrevious(); $inner !== null && count($previous) < self::DEPTH; $inner = $inner->getPrevious()) {
            $previous[] = self::one($inner, $file);
        }

        return [...self::one($throwable, $file), 'previous' => $previous];
    }

    /**
     * @param  callable(string): string  $file
     * @return array{class: class-string<Throwable>, message: string, file: string}
     */
    private static function one(Throwable $throwable, callable $file): array
    {
        return ['class' => $throwable::class, 'message' => $throwable->getMessage(), 'file' => $file($throwable->getFile()).':'.$throwable->getLine()];
    }

    private static function relative(string $file): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }
}
