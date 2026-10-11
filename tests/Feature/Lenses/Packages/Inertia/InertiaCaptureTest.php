<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Inertia;

use ExeQue\Monocle\Lenses\Packages\Inertia\InertiaCapture;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

covers(InertiaCapture::class);

describe(InertiaCapture::class, function (): void {
    $capture = fn (string $content, array $headers = []): ?array => app(InertiaCapture::class)->capture(
        Request::create('/orders', server: collect($headers)->mapWithKeys(fn (string $value, string $key): array => ['HTTP_'.strtoupper(str_replace('-', '_', $key)) => $value])->all()),
        new Response($content),
    );

    it('reads the page from an escaped data-page attribute', function () use ($capture): void {
        $page = htmlspecialchars('{"component":"Orders/Index","url":"/orders","props":{"a":1}}', ENT_QUOTES);

        $cards = $capture('<div id="app" data-page="'.$page.'"></div>', ['X-Inertia-Partial-Data' => '']);

        expect($cards[0]['title'])->toBe('Orders/Index')
            ->and($cards[0]['subtitle'])->toBe('/orders')
            ->and($cards[0]['items'])->toBe(['visit' => 'first load', 'props' => ['a' => 1]]);
    });

    it('falls back to the attribute when the script tag holds no page', function () use ($capture): void {
        $cards = $capture('<script data-page type="x">not json</script><div data-page="{&quot;component&quot;:&quot;Home&quot;}"></div>');

        expect($cards[0]['title'])->toBe('Home');
    });

    it('leaves alone JSON without a component and HTML without a readable page', function () use ($capture): void {
        expect($capture('{"data":[]}'))->toBeNull()
            ->and($capture('<div data-page></div>'))->toBeNull()
            ->and($capture('<div data-page="not json"></div>'))->toBeNull();
    });
});
