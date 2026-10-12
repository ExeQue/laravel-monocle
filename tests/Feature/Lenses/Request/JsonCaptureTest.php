<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Request;

use ExeQue\Monocle\Lenses\Request\JsonCapture;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

covers(JsonCapture::class);

describe(JsonCapture::class, function (): void {
    it('shows an object as its keys, a list with its count and a scalar as data', function (): void {
        $capture = new JsonCapture();
        $request = Request::create('/');

        expect($capture->format($request, new Response('{"a":1,"b":[2]}'))->response)->toBe([['title' => 'JSON', 'subtitle' => null, 'items' => ['a' => 1, 'b' => [2]]]])
            ->and($capture->format($request, new Response('[1,2,3]'))->response)->toBe([['title' => 'JSON', 'subtitle' => '3 items', 'items' => ['data' => [1, 2, 3]]]])
            ->and($capture->format($request, new Response('42'))->response)->toBe([['title' => 'JSON', 'subtitle' => null, 'items' => ['data' => 42]]]);
    });

    it('captures nothing for a streamed, empty or malformed body', function (): void {
        $capture = new JsonCapture();
        $request = Request::create('/');

        expect($capture->format($request, new StreamedResponse(fn () => null))->response)->toBeNull()
            ->and($capture->format($request, new Response(''))->response)->toBeNull()
            ->and($capture->format($request, new Response('{"a":'))->response)->toBeNull();
    });
});
