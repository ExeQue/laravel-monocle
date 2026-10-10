<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Request;

use ExeQue\Monocle\Contracts\ResponseCapture;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response body as it is, for text formats worth reading in full.
 */
final class TextCapture implements ResponseCapture
{
    public function capture(Request $request, Response $response): ?array
    {
        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return null;
        }

        return [[
            'title' => (string) ($response->headers->get('Content-Type') ?? 'Text'),
            'items' => ['body' => $content],
        ]];
    }
}
