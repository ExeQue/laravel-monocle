<?php

declare(strict_types=1);

namespace Workbench\App\Cache;

use Illuminate\Cache\ArrayStore;

/**
 * An in-memory store that answers like a distant one, so the showcase has
 * slow cache calls to flag.
 */
final class SlowStore extends ArrayStore
{
    public function get($key): mixed
    {
        usleep(30_000);

        return parent::get($key);
    }

    public function put($key, $value, $seconds): bool
    {
        usleep(30_000);

        return parent::put($key, $value, $seconds);
    }
}
