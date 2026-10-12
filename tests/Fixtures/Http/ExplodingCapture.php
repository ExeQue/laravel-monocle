<?php

declare(strict_types=1);

namespace Tests\Fixtures\Http;

use ExeQue\Monocle\Contracts\FormatsCapture;
use ExeQue\Monocle\Data\Formatted;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A response capture with a bug.
 */
final class ExplodingCapture implements FormatsCapture
{
    public function format(Request $request, Response $response): Formatted
    {
        throw new RuntimeException('capture exploded');
    }
}
