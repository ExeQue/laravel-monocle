<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Request;

use ExeQue\Monocle\Lenses\Request\TextCapture;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

covers(TextCapture::class);

describe(TextCapture::class, function (): void {
    it('shows the body under its content type', function (): void {
        $response = new Response('id,name', 200, ['Content-Type' => 'text/csv']);

        expect(new TextCapture()->format(Request::create('/'), $response)->response)->toBe([['title' => 'text/csv', 'subtitle' => null, 'items' => ['body' => 'id,name']]]);
    });

    it('captures nothing for a streamed or empty body', function (): void {
        $capture = new TextCapture();
        $request = Request::create('/');

        expect($capture->format($request, new StreamedResponse(fn () => null))->response)->toBeNull()
            ->and($capture->format($request, new Response(''))->response)->toBeNull();
    });
});
