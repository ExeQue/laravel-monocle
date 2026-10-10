<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Support\Masks;

/**
 * Replaces the whole value.
 */
final class FullMask
{
    public function __invoke(mixed $value, string $key): string
    {
        return '********';
    }
}
