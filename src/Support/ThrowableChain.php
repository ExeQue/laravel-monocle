<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Support;

use Throwable;

/**
 * A throwable as it is recorded: its class and message, plus the exceptions
 * it wraps, outermost first.
 */
final class ThrowableChain
{
    /** Previous exceptions recorded at most. */
    private const int DEPTH = 10;

    /**
     * @param  (callable(string): string)|null  $file  turns an absolute file into the path to record; no file when null
     * @return array{class: class-string<Throwable>, message: string, file?: string, previous: list<array{class: class-string<Throwable>, message: string, file?: string}>}
     */
    public static function describe(Throwable $throwable, ?callable $file = null): array
    {
        $previous = [];

        for ($inner = $throwable->getPrevious(); $inner !== null && count($previous) < self::DEPTH; $inner = $inner->getPrevious()) {
            $previous[] = self::one($inner, $file);
        }

        return [...self::one($throwable, $file), 'previous' => $previous];
    }

    /**
     * @return array{class: class-string<Throwable>, message: string, file?: string}
     */
    private static function one(Throwable $throwable, ?callable $file): array
    {
        $one = ['class' => $throwable::class, 'message' => $throwable->getMessage()];

        if ($file !== null) {
            $one['file'] = $file($throwable->getFile()).':'.$throwable->getLine();
        }

        return $one;
    }
}
