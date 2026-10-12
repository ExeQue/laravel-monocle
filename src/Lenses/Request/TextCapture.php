<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Request;

use ExeQue\Monocle\Contracts\FormatsCapture;
use ExeQue\Monocle\Data\Card;
use ExeQue\Monocle\Data\Formatted;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response body as it is, for text formats worth reading in full.
 */
final class TextCapture implements FormatsCapture
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

        return Formatted::make()->card(Card::titled((string) ($response->headers->get('Content-Type') ?? 'Text'))->item('body', $content));
    }
}
