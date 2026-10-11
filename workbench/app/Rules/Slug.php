<?php

declare(strict_types=1);

namespace Workbench\App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Lowercase letters, digits and dashes, no longer than the given length.
 */
final class Slug implements ValidationRule
{
    public function __construct(
        public readonly int $max = 60,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) !== 1) {
            $fail('The :attribute may only contain lowercase letters, digits and dashes.');

            return;
        }

        if (strlen($value) > $this->max) {
            $fail("The :attribute may not be longer than {$this->max} characters.");
        }
    }
}
