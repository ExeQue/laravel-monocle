<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Request;

use ExeQue\Monocle\Contracts\ResponseCapture;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A JSON response as one card with its top-level keys as items.
 */
final class JsonCapture implements ResponseCapture
{
    public function capture(Request $request, Response $response): ?array
    {
        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return [[
            'title' => 'JSON',
            'subtitle' => is_array($decoded) && array_is_list($decoded) ? count($decoded).' items' : null,
            'items' => is_array($decoded) && ! array_is_list($decoded) ? $decoded : ['data' => $decoded],
        ]];
    }
}
