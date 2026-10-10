<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns the response of a route into something easier to read than its raw
 * body. Register it for route names under the request lens option
 * capture.routes.
 */
interface ResponseCapture
{
    /**
     * Cards to show instead of the raw body: each a title, an optional
     * subtitle and named items (scalars, lists, maps, or change lists of
     * {field, from, to}). Return null to keep the normal body capture.
     *
     * @return list<array{title: string, subtitle?: string|null, items: array<string, mixed>}>|null
     */
    public function capture(Request $request, Response $response): ?array;
}
