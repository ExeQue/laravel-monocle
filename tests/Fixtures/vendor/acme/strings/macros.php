<?php

declare(strict_types=1);

/*
 * A macro registered by "library" code (the file sits in a vendor folder),
 * for the Origin tests.
 */

use Illuminate\Support\Stringable;

Stringable::macro('shout', function (): string {
    return strtoupper((string) $this);
});
