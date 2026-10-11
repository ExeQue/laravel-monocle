<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Queue;

use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Queue\SyncDispatchPipe;
use RuntimeException;
use Tests\Fixtures\Jobs\ExplodingCommand;
use Tests\Fixtures\Jobs\PlainCommand;

covers(SyncDispatchPipe::class);

describe(SyncDispatchPipe::class, function (): void {
    beforeEach(function (): void {
        PlainCommand::$handled = 0;
    });

    it('records a command dispatched at the top of a capture as a job span', function (): void {
        app(Eyepiece::class)->start(CaptureType::Command);

        PlainCommand::dispatchSync();

        $spans = array_values(array_filter(app(Eyepiece::class)->finalize()->spans, fn (Span $span): bool => $span->kind === 'job'));

        expect(PlainCommand::$handled)->toBe(1)
            ->and($spans)->toHaveCount(1)
            ->and($spans[0]->parentId)->toBeNull()
            ->and($spans[0]->meta)->toMatchArray(['sync' => true, 'via' => 'dispatchSync', 'status' => 'processed']);
    });

    it('marks the span of a command that throws', function (): void {
        app(Eyepiece::class)->start(CaptureType::Command);

        expect(fn () => ExplodingCommand::dispatchSync())->toThrow(RuntimeException::class, 'command exploded');

        $spans = array_values(array_filter(app(Eyepiece::class)->finalize()->spans, fn (Span $span): bool => $span->kind === 'job'));

        expect($spans)->toHaveCount(1)
            ->and($spans[0]->isOpen())->toBeFalse()
            ->and($spans[0]->meta['status'])->toBe('exception')
            ->and($spans[0]->meta['exception']['class'] ?? null)->toBe(RuntimeException::class);
    });

    it('only runs the command when nothing is captured', function (): void {
        PlainCommand::dispatchSync();

        expect(PlainCommand::$handled)->toBe(1);
    });

    it('only runs the command when the capture is discarded', function (): void {
        app(Eyepiece::class)->start(CaptureType::Command);
        app(Eyepiece::class)->discard();

        PlainCommand::dispatchSync();

        $spans = array_filter(app(Eyepiece::class)->active()->spans, fn (Span $span): bool => $span->kind === 'job');

        expect(PlainCommand::$handled)->toBe(1)
            ->and($spans)->toBe([]);
    });
});
