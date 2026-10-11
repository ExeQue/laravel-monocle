<?php

declare(strict_types=1);

namespace Tests\Fixtures\Http;

use LogicException;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Response headers that refuse Monocle's own headers, like an already-sent response.
 */
final class LockedHeaderBag extends ResponseHeaderBag
{
    public function set(string $key, string|array|null $values, bool $replace = true): void
    {
        if (str_starts_with($key, 'X-Monocle') || $key === 'Server-Timing') {
            throw new LogicException('Headers already sent.');
        }

        parent::set($key, $values, $replace);
    }
}
