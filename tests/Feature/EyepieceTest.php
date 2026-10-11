<?php

declare(strict_types=1);

namespace Tests\Feature;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Enums\SpanColor;
use ExeQue\Monocle\Exceptions\InvalidMaskException;
use ExeQue\Monocle\Exceptions\InvalidSpanColorException;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Facades\Monocle;
use ExeQue\Monocle\Lenses\HttpClientLens;
use ExeQue\Monocle\Lenses\RequestLens;
use ExeQue\Monocle\MonocleManager;
use ExeQue\Monocle\Reader\SummaryBuilder;
use ExeQue\Monocle\Support\Masks\FullMask;
use ExeQue\Monocle\Support\Masks\PartialMask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;

covers(Eyepiece::class, Span::class, MonocleManager::class, Capture::class, InvalidMaskException::class, InvalidSpanColorException::class);

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

        it('keeps the first start and stop of a span', function (): void {
            $span = Monocle::span('once')->start();
            $started = $span->start;

            $span->start(['again' => true]);
            $span->stop();
            $ended = $span->end;
            $span->stop(['late' => true]);

            expect($span->start)->toBe($started)
                ->and($span->end)->toBe($ended)
                ->and($span->meta)->toBe(['again' => true, 'late' => true])
                ->and($this->eyepiece->active()->spans)->toHaveCount(1);
        });

        it('has no duration until it has both started and stopped', function (): void {
            $span = Monocle::span('timed');

            expect($span->duration())->toBeNull();

            $span->start();

            expect($span->duration())->toBeNull();

            $span->stop();

            expect($span->duration())->toBeFloat();
        });

        it('can be moved under another span or to the top', function (): void {
            $parent = Monocle::span('parent');
            $child = Monocle::span('child')->parent($parent);

            expect($child->parentId)->toBe($parent->id)
                ->and($child->parent(null)->parentId)->toBeNull();
        });

        it('records nothing when started after the capture it was made in has ended', function (): void {
            $span = Monocle::span('late');
            $this->eyepiece->reset();

            expect($span->start()->start)->toBeNull()
                ->and($this->eyepiece->currentSpan())->toBeNull();
        });
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

            // Undated, the row would sit at $before or later; under load the push itself may take a few ms.
            expect($this->eyepiece->active()->rows('custom')[0]['time'])->toBeLessThan($before - 5.0)
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
                ->and($stored->health['finalize_ms'])->toBeGreaterThanOrEqual(0)
                ->and($stored->endedBy)->toBe('normal')
                ->and($this->eyepiece->active())->toBeNull();
        });

        it('credits listener work to the lens that registered it, without the application code it calls', function (): void {
            $this->eyepiece->registering('things', function (): void {
                $this->eyepiece->listen('monocle.test', function (): void {
                    usleep(5_000);
                    $this->eyepiece->outside(fn () => usleep(100_000));
                    $this->eyepiece->push('other', ['n' => 1]);
                });
            });

            event('monocle.test');
            $health = $this->eyepiece->finalize()->health;

            expect($health['overhead_by'])->toHaveKey('things')->not->toHaveKey('other')
                ->and($health['overhead_by']['things'])->toBeGreaterThanOrEqual(5.0)->toBeLessThan(50.0)
                ->and($health['overhead_ms'])->toBeLessThan(50.0);
        });

        it('counts Monocle work inside application code run through outside()', function (): void {
            $this->eyepiece->listen('monocle.inner', fn () => usleep(30_000));
            $this->eyepiece->registering('outer', function (): void {
                $this->eyepiece->listen('monocle.outer', function (): void {
                    $this->eyepiece->outside(function (): void {
                        usleep(100_000);
                        event('monocle.inner');
                    });
                });
            });

            event('monocle.outer');
            $by = $this->eyepiece->finalize()->health['overhead_by'];

            // The 100ms of application code count for no one; the 30ms listener inside it counts once, for itself.
            expect($by['monocle'])->toBeGreaterThanOrEqual(30.0)->toBeLessThan(80.0)
                ->and($by['outer'])->toBeLessThan(30.0)
                ->and($this->eyepiece->outside(fn () => 'plain'))->toBe('plain');
        });

        it('leaves nested application code out once', function (): void {
            $this->eyepiece->registering('outer', function (): void {
                $this->eyepiece->listen('monocle.inner', function (): void {
                    $this->eyepiece->outside(fn () => usleep(100_000));
                });
                $this->eyepiece->listen('monocle.outer', function (): void {
                    usleep(5_000);
                    $this->eyepiece->outside(fn () => event('monocle.inner'));
                });
            });

            event('monocle.outer');

            expect($this->eyepiece->finalize()->health['overhead_by']['outer'])->toBeGreaterThanOrEqual(5.0)->toBeLessThan(50.0);
        });

        it('times hooks wrapped for other APIs and returns what they return', function (): void {
            $hook = $this->eyepiece->wrap(function (int $n): int {
                usleep(5_000);

                return $n * 2;
            }, 'gate');

            expect($hook(21))->toBe(42)
                ->and($this->eyepiece->finalize()->health['overhead_by']['gate'])->toBeGreaterThanOrEqual(5.0);
        });

        it('never lets a listener stop the event for the application', function (): void {
            $this->eyepiece->listen('monocle.stop', fn () => false);
            $reached = false;
            Event::listen('monocle.stop', function () use (&$reached): void {
                $reached = true;
            });

            event('monocle.stop');

            expect($reached)->toBeTrue();
        });

        it('credits a listener that finalizes a capture to that capture, and what follows to the next', function (): void {
            $this->eyepiece->registering('jobs', function (): void {
                $this->eyepiece->listen('monocle.finish', function (): void {
                    usleep(50_000);
                    $this->eyepiece->finalize();
                    $this->eyepiece->start(CaptureType::Job);
                    usleep(3_000);
                });
            });

            $first = $this->eyepiece->active();
            event('monocle.finish');

            $stored = app(\ExeQue\Monocle\Storage\StoreManager::class)->store()->find($first->id);
            $next = $this->eyepiece->finalize();

            expect($stored->health['overhead_by']['jobs'])->toBeGreaterThanOrEqual(50.0)
                ->and($next->health['overhead_by']['jobs'])->toBeGreaterThanOrEqual(3.0)->toBeLessThan(30.0);
        });

        it('credits rows written outside a listener to their section', function (): void {
            $this->eyepiece->push('things', ['n' => 1]);
            Monocle::span('a')->start()->stop();

            expect(array_keys($this->eyepiece->finalize()->health['overhead_by']))->toContain('things', 'timeline');
        });

        it('leaves application code a lens runs while registering out of its time', function (): void {
            $this->eyepiece->registering('booting', fn () => $this->eyepiece->outside(fn () => usleep(100_000)));

            expect($this->eyepiece->finalize()->health['overhead_by']['booting'])->toBeLessThan(50.0);
        });

        it('counts registering lenses as overhead, on the running capture or the next one', function (): void {
            $this->eyepiece->registering('slow', fn () => usleep(10_000));

            expect($this->eyepiece->finalize()->health['overhead_by']['slow'])->toBeGreaterThanOrEqual(10.0);

            $this->eyepiece->registering('slow', fn () => usleep(10_000));
            $this->eyepiece->start(CaptureType::Command);

            expect($this->eyepiece->finalize()->health['overhead_by']['slow'])->toBeGreaterThanOrEqual(10.0);

            $this->eyepiece->start(CaptureType::Command);

            expect($this->eyepiece->finalize()->health['overhead_by'])->not->toHaveKey('slow');
        });
    });

    it('runs section modifiers on every pushed row', function (): void {
        $this->eyepiece->modify('custom', fn (array $row): array => [...$row, 'modified' => true]);
        $this->eyepiece->push('custom', ['a' => 1], origin: false);
        $this->eyepiece->push('other', ['b' => 2], origin: false);

        expect($this->eyepiece->active()->rows('custom')[0])->toMatchArray(['a' => 1, 'modified' => true])
            ->and($this->eyepiece->active()->rows('other')[0])->not->toHaveKey('modified');
    });

    it('leaves origins out when they are turned off', function (): void {
        config(['monocle.origin.enabled' => false]);

        $this->eyepiece->push('custom', ['a' => 1]);

        expect($this->eyepiece->active()->rows('custom')[0]['origin'])->toBeNull()
            ->and($this->eyepiece->origin())->toBeNull();
    });

    it('lets only the outer finalize finish a capture that is ended again while finalizing', function (): void {
        $builder = app(SummaryBuilder::class);
        $inner = 'not called';

        $this->app->bind(SummaryBuilder::class, function () use ($builder, &$inner): SummaryBuilder {
            $inner = $this->eyepiece->finalize('nested');

            return $builder;
        });

        $capture = $this->eyepiece->finalize();

        expect($inner)->toBeNull()
            ->and($capture?->endedBy)->toBe('normal')
            ->and(latestCapture()?->id)->toBe($capture?->id);
    });

    it('drops a capture it fails to finalize and resets', function (): void {
        config(['monocle.throw_failures' => false]);
        Log::spy();

        $this->app->bind(SummaryBuilder::class, function (): never {
            throw new RuntimeException('summary broke');
        });

        expect($this->eyepiece->finalize())->toBeNull()
            ->and($this->eyepiece->active())->toBeNull()
            ->and(latestCapture())->toBeNull();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'summary broke'));
    });

    it('stays silent when the log itself is unavailable', function (): void {
        config(['monocle.throw_failures' => false]);

        $this->app->bind('log', function (): never {
            throw new RuntimeException('no log');
        });

        $this->eyepiece->failed(new RuntimeException('lens broke'));

        expect($this->eyepiece->finalize()->health['failures'])->toHaveCount(1);
    });

    describe('safety', function (): void {
        it('keeps a listener failure out of the application, on the capture and in the log once', function (): void {
            config(['monocle.throw_failures' => false]);
            Log::spy();

            $this->eyepiece->listen('monocle.test.boom', function (): void {
                throw new RuntimeException('listener broke');
            });

            $this->eyepiece->start(\ExeQue\Monocle\Enums\CaptureType::Request);
            event('monocle.test.boom');
            event('monocle.test.boom');
            $capture = $this->eyepiece->finalize();

            Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'listener broke'));

            expect($capture->health['failures'])->toHaveCount(1)
                ->and($capture->health['failures'][0])->toMatchArray(['exception' => RuntimeException::class, 'message' => 'listener broke'])
                ->and(app(\ExeQue\Monocle\Reader\SummaryBuilder::class)->build($capture)['warnings'])->toContain('Monocle failed to record part of this capture: listener broke ('.$capture->health['failures'][0]['file'].').');
        });

        it('throws a listener failure when asked to', function (): void {
            $this->eyepiece->listen('monocle.test.boom', function (): void {
                throw new RuntimeException('listener broke');
            });

            expect(fn () => event('monocle.test.boom'))->toThrow(RuntimeException::class, 'listener broke');
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

        it('applies mask entries keyed by a lens class to that lens only', function (): void {
            config([
                'monocle.mask.headers' => [
                    'authorization' => FullMask::class,
                    HttpClientLens::class => ['x-api-key' => FullMask::class, 'authorization' => [PartialMask::class, 2]],
                ],
                'monocle.mask.inputs' => ['/pass/i', HttpClientLens::class => '/client_id/i'],
            ]);

            $headers = ['Authorization' => 'Bearer abcdefghijklmnop', 'X-Api-Key' => 'abcdefghijklmnop'];
            $input = ['password' => 'x', 'client_id' => 'abcdefghijklmnop'];

            expect($this->eyepiece->masker()->headers($headers))->toBe(['Authorization' => '********', 'X-Api-Key' => 'abcdefghijklmnop'])
                ->and($this->eyepiece->masker(RequestLens::class)->inputs($input))->toBe(['password' => '****', 'client_id' => 'abcdefghijklmnop'])
                ->and($this->eyepiece->masker(HttpClientLens::class)->headers($headers))->toBe(['Authorization' => 'Be****', 'X-Api-Key' => '********'])
                ->and($this->eyepiece->masker(HttpClientLens::class)->inputs($input))->toBe(['password' => '****', 'client_id' => 'abcd****']);
        });

        it('refuses masks that are not class names', function (string $key, mixed $mask): void {
            config(["monocle.{$key}" => $mask]);

            expect(fn () => $this->eyepiece->masker())->toThrow(InvalidMaskException::class);
        })->with([
            'closure as using' => ['mask.using', fn (): string => 'x'],
            'unknown class for a header' => ['mask.headers', ['authorization' => 'NoSuchMask']],
            'array without a class' => ['mask.cookies', ['remember_*' => ['visible' => 8]]],
        ]);

        it('builds masks with constructor arguments', function (): void {
            config([
                'monocle.mask.using' => [PartialMask::class, 'visible' => 2],
                'monocle.mask.headers' => ['authorization' => [PartialMask::class, 8]],
            ]);

            $masker = $this->eyepiece->masker();

            expect($masker->headers(['Authorization' => str_repeat('a', 40)])['Authorization'])->toBe('aaaaaaaa****')
                ->and($masker->inputs(['password' => str_repeat('b', 40)])['password'])->toBe('bb****');
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

        it('follows app.debug outside the test runner and is off in production', function (): void {
            config(['monocle.enabled' => null, 'app.debug' => true]);

            $this->app['env'] = 'production';

            expect($this->eyepiece->enabled())->toBeFalse();

            $this->app['env'] = 'local';

            expect($this->eyepiece->enabled())->toBeTrue();

            config(['app.debug' => false]);

            expect($this->eyepiece->enabled())->toBeFalse();
        });
    });
});
