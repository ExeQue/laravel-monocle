<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts;

use ExeQue\Monocle\Data\Formatted;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a request and its response into something easier to read than
 * their raw form: payload fields in a format, and the response as cards.
 * Register it for route names, response headers or content types under
 * the request lens option capture (and the HTTP client lens option
 * capture.response_types for outgoing calls).
 */
interface FormatsCapture
{
    /**
     * Leave a part of the result null to keep that side as it is: the
     * response is then kept only as its raw body and size, and the payload
     * as plain fields.
     */
    public function format(Request $request, Response $response): Formatted;
}
