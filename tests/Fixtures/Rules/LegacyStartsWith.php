<?php

declare(strict_types=1);

namespace Tests\Fixtures\Rules;

use Illuminate\Contracts\Validation\Rule;
use Stringable;

/**
 * A legacy Rule contract rule that writes itself as text, like the built-in rule objects.
 */
final class LegacyStartsWith implements Rule, Stringable
{
    public function __construct(
        private readonly string $prefix,
    ) {
    }

    public function passes($attribute, $value): bool
    {
        return str_starts_with((string) $value, $this->prefix);
    }

    public function message(): string
    {
        return 'The :attribute must start with '.$this->prefix.'.';
    }

    public function __toString(): string
    {
        return 'starts_with:'.$this->prefix;
    }
}
