<?php

declare(strict_types=1);

namespace Tests\Fixtures\Http;

use ExeQue\Monocle\Contracts\ResponseCapture;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A response capture with a bug.
 */
final class ExplodingCapture implements ResponseCapture
{
    public function capture(Request $request, Response $response): ?array
    {
        throw new RuntimeException('capture exploded');
    }
}
