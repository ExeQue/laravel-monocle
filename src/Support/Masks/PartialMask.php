<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Support\Masks;

/**
 * Default mask: keeps a short prefix visible so an agent can tell which token
 * or password was sent without seeing it. Shows at most `visible` characters
 * and never more than a quarter of the value.
 */
final class PartialMask
{
    public function __construct(private readonly int $visible = 4)
    {
    }

    public function __invoke(mixed $value, string $key): mixed
    {
        if (! is_scalar($value)) {
            return '****';
        }

        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        $keep = min($this->visible, (int) floor(mb_strlen($value) * 0.25));

        return mb_substr($value, 0, $keep) . '****';
    }
}
