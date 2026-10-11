<?php

declare(strict_types=1);

namespace Tests\Fixtures\Http;

use ExeQue\Monocle\Contracts\ResponseCapture;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A response capture that never reads the response body.
 */
final class StaticCardCapture implements ResponseCapture
{
    public function capture(Request $request, Response $response): ?array
    {
        return [['title' => 'Static', 'items' => ['status' => $response->getStatusCode()]]];
    }
}
