<?php

declare(strict_types=1);

namespace Tests\Feature;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Enums\SpanColor;
use ExeQue\Monocle\Exceptions\InvalidSpanColorException;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Facades\Monocle;
use ExeQue\Monocle\MonocleManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RuntimeException;

covers(Eyepiece::class, Span::class, MonocleManager::class, Capture::class);

describe(Eyepiece::class, function (): void {
    beforeEach(function (): void {
        $this->eyepiece = app(Eyepiece::class);
        $this->eyepiece->start(CaptureType::Request);
    });

    describe('spans', function (): void {
        it('creates independent spans for the same name', function (): void {
            $spans = [];

            for ($i = 0; $i < 10; $i++) {
                $spans[] = Monocle::span('loop')->start()->stop();
            }

            expect(array_unique(array_map(fn (Span $span): string => $span->id, $spans)))->toHaveCount(10)
                ->and($this->eyepiece->active()->spans)->toHaveCount(10);
        });

        it('nests spans through the stack and allows unordered stops', function (): void {
            $outer = Monocle::span('outer')->start();
            $inner = Monocle::span('inner')->start();
            $sibling = Monocle::span('sibling');

            $outer->stop();
            $sibling->start()->stop();
            $inner->stop();

            expect($inner->parentId)->toBe($outer->id)
                ->and($sibling->parentId)->toBe($inner->id)
                ->and($inner->duration())->toBeGreaterThanOrEqual(0);
        });

        it('looks spans up by key only when a key is given', function (): void {
            Monocle::span('import', key: 'import:1')->start();
            $again = Monocle::span(key: 'import:1');

            expect($again->name)->toBe('import')
                ->and($again->isOpen())->toBeTrue();

            $again->stop();

            expect(Monocle::span(key: 'import:1')->isOpen())->toBeFalse()
                ->and(Monocle::span('x')->id)->not->toBe(Monocle::span('x')->id);
        });

        it('merges metadata from start, stop and later meta calls', function (): void {
            $span = Monocle::span('work')->start(['a' => 1, 'keep' => true]);
            $span->stop(['b' => 2]);
            $span->meta(['a' => 3]);

            expect($span->meta)->toBe(['a' => 3, 'keep' => true, 'b' => 2]);
        });

        it('normalizes models in metadata', function (): void {
            $model = new class () extends Model {
                protected $guarded = [];
            };
            $model->id = 42;

            $span = Monocle::span('m')->meta(['model' => $model]);

            expect($span->meta['model'])->toEndWith('#42');
        });

        it('measures callbacks and returns their result', function (): void {
            $result = Monocle::measure('calc', fn (): int => 21 * 2, ['tag' => 'x']);
            $span = array_values($this->eyepiece->active()->spans)[0];

            expect($result)->toBe(42)
                ->and($span->name)->toBe('calc')
                ->and($span->meta['tag'])->toBe('x')
                ->and($span->isOpen())->toBeFalse();
        });

        it('closes open spans at finalize and flags them', function (): void {
            Monocle::span('forgotten')->start();

            $capture = $this->eyepiece->finalize();

            expect($capture->spans)->toHaveCount(1)
                ->and(array_values($capture->spans)[0]->endedBy)->toBe('finalize')
                ->and($capture->health['open_spans_at_finalize'])->toBe(1);
        });
        it('takes a colour from the curated list or as a hex value and keeps it through storage', function (): void {
            $named = Monocle::span('named')->color(SpanColor::Teal)->start()->stop();
            $byValue = Monocle::span('by value')->color('lavender')->start()->stop();
            $hex = Monocle::span('hex')->color('#FF8800')->start()->stop();
            $short = Monocle::span('short')->color('#f80');

            $restored = Capture::fromArray($this->eyepiece->finalize()->toArray())->spans;

            expect($named->color)->toBe('teal')
                ->and($byValue->color)->toBe('lavender')
                ->and($short->color)->toBe('#f80')
                ->and($restored[$hex->id]->color)->toBe('#FF8800')
                ->and($restored[$named->id]->color)->toBe('teal');
        });

        it('rejects colours it cannot draw', function (string $color): void {
            Monocle::span('bad')->color($color);
        })->throws(InvalidSpanColorException::class)->with(['orange', '#ff88', 'red; background: url(x)', '']);
    });

    describe('capture metadata', function (): void {
        it('merges capture meta and stores a label', function (): void {
            Monocle::meta(['album' => 1])->meta(['album' => 2, 'user' => 9])->label('baseline');

            $capture = $this->eyepiece->finalize();

            expect($capture->meta)->toBe(['album' => 2, 'user' => 9])
                ->and($capture->label)->toBe('baseline');
        });

        it('attaches origin and time to pushed rows', function (): void {
            $this->eyepiece->push('custom', ['value' => 1]);

            $row = $this->eyepiece->active()->rows('custom')[0];

            expect($row['time'])->toBeFloat()
                ->and($row['origin']['file'])->toContain('EyepieceTest.php');
        });

        it('dates a row with a duration back to when the operation started', function (): void {
            usleep(20_000);
            $before = $this->eyepiece->now();
            $this->eyepiece->push('custom', ['duration' => 15.0]);

            expect($this->eyepiece->active()->rows('custom')[0]['time'])->toBeLessThanOrEqual($before - 15.0 + 1.0)
                ->toBeGreaterThanOrEqual($before - 15.0);
        });

        it('skips origin when asked', function (): void {
            $this->eyepiece->push('custom', ['value' => 1], origin: false);

            expect($this->eyepiece->active()->rows('custom')[0])->not->toHaveKey('origin');
        });
    });

    describe('lifecycle', function (): void {
        it('is a no-op without an active capture', function (): void {
            $this->eyepiece->reset();

            $span = Monocle::span('idle')->start()->stop();
            Monocle::meta(['x' => 1]);

            expect($span->start)->toBeNull()
                ->and($this->eyepiece->active())->toBeNull()
                ->and(Monocle::measure('m', fn (): string => 'ok'))->toBe('ok');
        });

        it('does not store discarded captures', function (): void {
            $this->eyepiece->discard();

            expect($this->eyepiece->finalize())->toBeNull()
                ->and(latestCapture())->toBeNull();
        });

        it('stores finalized captures with duration and overhead', function (): void {
            Monocle::span('a')->start()->stop();

            $capture = $this->eyepiece->finalize();
            $stored = latestCapture();

            expect($stored->id)->toBe($capture->id)
                ->and($stored->duration())->toBeGreaterThan(0)
                ->and($stored->health['overhead_ms'])->toBeGreaterThanOrEqual(0)
                ->and($stored->endedBy)->toBe('normal')
                ->and($this->eyepiece->active())->toBeNull();
        });
    });

    describe('safety', function (): void {
        it('swallows and logs listener failures once', function (): void {
            Log::spy();

            $this->eyepiece->listen('monocle.test.boom', function (): void {
                throw new RuntimeException('listener broke');
            });

            event('monocle.test.boom');
            event('monocle.test.boom');

            Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'listener broke'));
        });

        it('exposes the innermost open span and relative times', function (): void {
            expect($this->eyepiece->currentSpan())->toBeNull();

            $outer = Monocle::span('outer')->start();

            expect($this->eyepiece->currentSpan()?->id)->toBe($outer->id)
                ->and($this->eyepiece->relative(microtime(true)))->toBeGreaterThanOrEqual(0)
                ->and($this->eyepiece->config('storage.limit'))->toBe(10);
        });

        it('merges associative sections and ignores set() on list sections', function (): void {
            $this->eyepiece->set('info', ['a' => 1]);
            $this->eyepiece->set('info', ['b' => 2]);
            $this->eyepiece->push('rows', ['x' => 1]);
            $this->eyepiece->set('rows', ['replaced' => true]);

            expect($this->eyepiece->active()->section('info'))->toBe(['a' => 1, 'b' => 2])
                ->and($this->eyepiece->active()->sections['rows'])->toBe(['replaced' => true]);
        });

        it('builds the masker from config with a callable mask', function (): void {
            config(['monocle.mask.using' => fn (mixed $value, string $key): string => "masked:{$key}"]);

            expect($this->eyepiece->masker()->inputs(['password' => 'x'])['password'])->toBe('masked:password');
        });

        it('reports nothing when no capture is active', function (): void {
            $this->eyepiece->reset();

            expect($this->eyepiece->now())->toBe(0.0)
                ->and($this->eyepiece->relative(microtime(true)))->toBe(0.0)
                ->and($this->eyepiece->recording())->toBeFalse()
                ->and($this->eyepiece->finalize())->toBeNull();

            $this->eyepiece->push('x', ['a' => 1]);
            $this->eyepiece->set('x', ['a' => 1]);
            $this->eyepiece->describe(name: 'nothing');

            expect($this->eyepiece->active())->toBeNull();
        });
    });

    describe('enabled', function (): void {
        it('follows the runtime override before config', function (): void {
            config(['monocle.enabled' => false]);
            Monocle::enabled(fn (): bool => true);

            expect(Monocle::isEnabled())->toBeTrue();

            Monocle::enabled(false);

            expect(Monocle::isEnabled())->toBeFalse();
        });

        it('is off in production and in the test runner unless explicitly enabled', function (): void {
            config(['monocle.enabled' => null, 'app.debug' => true]);

            expect($this->eyepiece->enabled())->toBeFalse();

            config(['monocle.enabled' => true]);

            expect($this->eyepiece->enabled())->toBeTrue();
        });
    });
});
