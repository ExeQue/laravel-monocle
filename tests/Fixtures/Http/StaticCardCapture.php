<?php

declare(strict_types=1);

namespace Tests\Fixtures\Http;

use ExeQue\Monocle\Contracts\FormatsCapture;
use ExeQue\Monocle\Data\Card;
use ExeQue\Monocle\Data\Formatted;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A response capture that never reads the response body.
 */
final class StaticCardCapture implements FormatsCapture
{
    public function format(Request $request, Response $response): Formatted
    {
        return Formatted::make()->card(Card::titled('Static')->item('status', $response->getStatusCode()));
    }
}
