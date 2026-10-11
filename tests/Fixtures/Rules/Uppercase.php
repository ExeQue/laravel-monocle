<?php

declare(strict_types=1);

namespace Tests\Fixtures\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class Uppercase implements ValidationRule
{
    public function __construct(
        public readonly int $minimum = 1,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (strtoupper((string) $value) !== $value) {
            $fail('The :attribute must be uppercase.');
        }
    }
}
