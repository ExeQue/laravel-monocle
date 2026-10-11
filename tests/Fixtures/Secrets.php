<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * An object with something to hide at every visibility, for dump masking.
 */
final class Secrets
{
    public string $open = 'visible';

    public string $note = 'note to self';

    protected string $token = 'token-1234567890';

    private string $pin = '1234';
}
