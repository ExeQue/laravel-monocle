<?php

declare(strict_types=1);

namespace Tests\Fixtures\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * A rule written against the legacy Rule contract, without public properties.
 */
final class LegacyNotBlank implements Rule
{
    public function passes($attribute, $value): bool
    {
        return trim((string) $value) !== '';
    }

    public function message(): string
    {
        return 'The :attribute must not be blank.';
    }
}
