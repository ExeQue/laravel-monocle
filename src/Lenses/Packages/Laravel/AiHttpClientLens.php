<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Laravel;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\ExtendsLens;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\HttpClientLens;
use ExeQue\Monocle\Support\Masks\PartialMask;

/**
 * With laravel/ai: masks the API keys its providers send in a header of
 * their own, on the HTTP client section. The providers that send
 * `Authorization: Bearer` are masked by mask.headers already.
 */
final class AiHttpClientLens implements ExtendsLens, Lens, RequiresPackages
{
    /** Header (lowercase) => the laravel/ai gateways that send their key in it. */
    private const array KEY_HEADERS = [
        'x-api-key' => 'Anthropic',
        'api-key' => 'Azure OpenAI',
        'x-goog-api-key' => 'Gemini',
        'xi-api-key' => 'ElevenLabs',
    ];

    /** A value no mask leaves as it is, to ask whether mask.headers covers a header. */
    private const string PROBE = 'monocle-probe-value';

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'http_ai';
    }

    public static function packages(): array
    {
        return ['laravel/ai'];
    }

    public static function extends(): string
    {
        return HttpClientLens::class;
    }

    public function description(): string
    {
        return 'With laravel/ai: the provider API key headers ('.implode(', ', array_keys(self::KEY_HEADERS)).') are masked on every row.';
    }

    public function boot(): void
    {
        $mask = new PartialMask();

        $this->eyepiece->modify('http', function (array $row) use ($mask): array {
            $masker = $this->eyepiece->masker(HttpClientLens::class);

            foreach ($row['request_headers'] ?? [] as $name => $value) {
                $name = (string) $name;

                if (! isset(self::KEY_HEADERS[strtolower($name)])) {
                    continue;
                }

                // A header mask.headers covers was masked already, the way the config says.
                if ($masker->headers([$name => self::PROBE])[$name] !== self::PROBE) {
                    continue;
                }

                $row['request_headers'][$name] = $mask($value, $name);
            }

            return $row;
        });
    }
}
