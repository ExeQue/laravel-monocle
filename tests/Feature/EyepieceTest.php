<?php

declare(strict_types=1);

use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Facades\Monocle;
use Illuminate\Database\Eloquent\Model;

covers(Eyepiece::class, Span::class, ExeQue\Monocle\MonocleManager::class, ExeQue\Monocle\Data\Capture::class);

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
