<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Request;

use ExeQue\Monocle\Contracts\FormatsCapture;
use ExeQue\Monocle\Data\Card;
use ExeQue\Monocle\Data\Formatted;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A JSON response as one card with its top-level keys as items.
 */
final class JsonCapture implements FormatsCapture
{
    public function format(Request $request, Response $response): Formatted
    {
        $content = $response->getContent();

        if (! is_string($content)) {
            return Formatted::make();
        }

        if ($content === '') {
            return Formatted::make();
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return Formatted::make();
        }

        // An object shows its keys as items; a list or a scalar is one "data" item.
        $isObject = is_array($decoded) && ! array_is_list($decoded);
        $isList = is_array($decoded) && array_is_list($decoded);

        return Formatted::make()->card(
            Card::titled('JSON')
                ->subtitle($isList ? count($decoded).' items' : null)
                ->items($isObject ? $decoded : ['data' => $decoded]),
        );
    }
}
