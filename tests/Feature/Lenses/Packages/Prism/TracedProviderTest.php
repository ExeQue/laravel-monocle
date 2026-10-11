<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Prism;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Prism\PrismLens;
use ExeQue\Monocle\Lenses\Packages\Prism\TracedProvider;
use Generator;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse as AudioTextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Images\Request as ImagesRequest;
use Prism\Prism\Images\Response as ImagesResponse;
use Prism\Prism\Moderation\Request as ModerationRequest;
use Prism\Prism\Moderation\Response as ModerationResponse;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\GeneratedAudio;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;

covers(TracedProvider::class);

/**
 * A provider that answers every operation with a fixed response, for the
 * operations Prism::fake() cannot script.
 */
function scriptedPrismProvider(): Provider
{
    return new class () extends Provider {
        public string $region = 'eu-north';

        public function images(ImagesRequest $request): ImagesResponse
        {
            return new ImagesResponse([new GeneratedImage(url: 'https://example.com/a.png')], new Usage(7, 0), new Meta('img-1', 'dall-e-3'));
        }

        public function moderation(ModerationRequest $request): ModerationResponse
        {
            return new ModerationResponse([], new Meta('mod-1', 'omni-moderation'));
        }

        public function textToSpeech(TextToSpeechRequest $request): AudioResponse
        {
            return new AudioResponse(new GeneratedAudio(base64: 'ZmFrZQ==', type: 'audio/mpeg'));
        }

        public function speechToText(SpeechToTextRequest $request): AudioTextResponse
        {
            return new AudioTextResponse('transcribed', new Usage(3, 1));
        }

        public function stream(TextRequest $request): Generator
        {
            yield new StreamStartEvent('e1', 0, 'scripted-model', 'scripted');
            yield new ToolCallEvent('e2', 0, new ToolCall('t1', 'search', []), 'm1');
            yield new ErrorEvent('e3', 0, 'rate_limit', 'slow down', true);

            if ($request->prompt() === 'explode') {
                throw new RuntimeException('stream broke');
            }

            yield new TextDeltaEvent('e4', 0, 'partial', 'm1');
            yield new StreamEndEvent('e5', 0, FinishReason::Stop, new Usage(2, 1));
        }

        public function ping(string $value): string
        {
            return 'pong '.$value;
        }
    };
}

describe(TracedProvider::class, function (): void {
    beforeEach(function (): void {
        app(PrismManager::class)->extend('scripted', fn (): Provider => scriptedPrismProvider());
        app(Eyepiece::class)->start(CaptureType::Request);
    });

    afterEach(function (): void {
        app(Eyepiece::class)->reset();
    });

    it('records images, moderation, text to speech and speech to text calls', function (): void {
        Prism::image()->using('scripted', 'dall-e')->withPrompt('A lighthouse')->generate();
        Prism::moderation()->using('scripted', 'omni')->withInput('Is this fine?')->asModeration();
        Prism::audio()->using('scripted', 'tts-1')->withInput('Read this aloud')->withVoice('alloy')->asAudio();
        Prism::audio()->using('scripted', 'whisper')->withInput(Audio::fromBase64('ZmFrZQ==', 'audio/mpeg'))->asText();

        $rows = collect(app(Eyepiece::class)->finalize()->rows('prism'))->keyBy('operation');

        expect($rows['images'])->toMatchArray(['status' => 'ok', 'model' => 'dall-e-3', 'prompt' => 'A lighthouse'])
            ->and($rows['images']['tokens']['input'])->toBe(7)
            ->and($rows['moderation'])->toMatchArray(['status' => 'ok', 'model' => 'omni-moderation', 'prompt' => null])
            ->and($rows['text_to_speech'])->toMatchArray(['status' => 'ok', 'prompt' => 'Read this aloud'])
            ->and($rows['speech_to_text'])->toMatchArray(['status' => 'ok', 'prompt' => null, 'response' => 'transcribed']);
    });

    it('records tool calls and errors from a stream and keeps text under the limit whole', function (): void {
        $lens = app(PrismLens::class);
        $lens->configure(['max_text' => 100]);
        $lens->boot($this->app);
        app(PrismManager::class)->extend('scripted', fn (): Provider => scriptedPrismProvider());

        iterator_to_array(Prism::text()->using('scripted', 'm')->withPrompt('short')->asStream(), false);

        $row = app(Eyepiece::class)->finalize()->rows('prism')[0];

        expect($row)->toMatchArray([
            'status' => 'ok',
            'prompt' => 'short',
            'response' => 'partial',
            'tool_calls' => 1,
            'error' => 'slow down',
        ]);
    });

    it('records a stream that throws as failed and rethrows', function (): void {
        $read = fn () => iterator_to_array(Prism::text()->using('scripted', 'm')->withPrompt('explode')->asStream(), false);

        expect($read)->toThrow(RuntimeException::class, 'stream broke');

        $row = app(Eyepiece::class)->finalize()->rows('prism')[0];

        expect($row['status'])->toBe('failed')
            ->and($row['exception']['message'])->toBe('stream broke');
    });

    it('passes a stream straight through outside a capture', function (): void {
        app(Eyepiece::class)->reset();

        $events = iterator_to_array(Prism::text()->using('scripted', 'm')->withPrompt('quiet')->asStream(), false);

        app(Eyepiece::class)->start(CaptureType::Request);

        expect($events)->not->toBeEmpty()
            ->and(app(Eyepiece::class)->finalize()->rows('prism'))->toBe([]);
    });

    it('forwards unknown methods, properties and request exceptions to the wrapped provider', function (): void {
        $inner = scriptedPrismProvider();
        $traced = new TracedProvider($inner, app(Eyepiece::class), null);
        $exception = new RequestException(new ClientResponse(new Psr7Response(429)));

        expect($traced->inner())->toBe($inner)
            ->and($traced->ping('Ada'))->toBe('pong Ada')
            ->and($traced->region)->toBe('eu-north')
            ->and(fn () => $traced->handleRequestException('m', $exception))->toThrow(PrismRateLimitedException::class);
    });
});
