<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Validation\Validator;

/**
 * A validator an application could set through Validator::resolver().
 */
final class RecordingValidator extends Validator
{
    public static int $made = 0;
}
