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

        if (! is_string($content)) {
            return null;
        }

        if ($content === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        // An object shows its keys as items; a list or a scalar is one "data" item.
        $isObject = is_array($decoded) && ! array_is_list($decoded);
        $isList = is_array($decoded) && array_is_list($decoded);

        return [[
            'title' => 'JSON',
            'subtitle' => $isList ? count($decoded).' items' : null,
            'items' => $isObject ? $decoded : ['data' => $decoded],
        ]];
    }
}
