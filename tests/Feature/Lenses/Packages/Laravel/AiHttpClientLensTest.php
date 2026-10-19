<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Laravel;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\HttpClientLens;
use ExeQue\Monocle\Lenses\Packages\Laravel\AiHttpClientLens;
use ExeQue\Monocle\Support\Masks\FullMask;
use Illuminate\Support\Facades\Http;

covers(AiHttpClientLens::class);

/**
 * @param  array<string, string>  $sent
 * @return array<string, mixed>
 */
function recordedHeaders(array $sent): array
{
    app(Eyepiece::class)->start(CaptureType::Request);

    Http::withHeaders($sent)->post('https://api.example.com/v1/messages', ['prompt' => 'hi']);

    return app(Eyepiece::class)->finalize()->rows('http')[0]['request_headers'];
}

describe(AiHttpClientLens::class, function (): void {
    beforeEach(function (): void {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true])]);
    });

    it('masks the API key headers of the laravel/ai providers', function (): void {
        $recorded = recordedHeaders([
            'x-api-key' => 'sk-ant-api03-abcdefghijklmnop',
            'Api-Key' => 'azure-abcdefghijklmnop',
            'x-goog-api-key' => 'AIzaSyabcdefghijklmnop',
            'xi-api-key' => 'eleven-abcdefghijklmnop',
            'anthropic-version' => '2023-06-01',
        ]);

        expect($recorded)->toMatchArray([
            'x-api-key' => 'sk-a****',
            'Api-Key' => 'azur****',
            'x-goog-api-key' => 'AIza****',
            'xi-api-key' => 'elev****',
            'anthropic-version' => '2023-06-01',
        ]);
    });

    it('leaves a header to the mask the config sets for it', function (): void {
        config(['monocle.mask.headers' => [HttpClientLens::class => ['x-api-key' => FullMask::class]]]);

        expect(recordedHeaders(['x-api-key' => 'sk-ant-api03-abcdefghijklmnop'])['x-api-key'])->toBe('********');
    });

    it('extends the HTTP client lens and needs laravel/ai', function (): void {
        expect(AiHttpClientLens::extends())->toBe(HttpClientLens::class)
            ->and(AiHttpClientLens::packages())->toBe(['laravel/ai'])
            ->and(app(AiHttpClientLens::class)->key())->toBe('http_ai')
            ->and(app(AiHttpClientLens::class)->description())->toContain('x-api-key');
    });
});
