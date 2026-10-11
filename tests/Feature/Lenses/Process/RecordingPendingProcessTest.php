<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Process;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Process\RecordingPendingProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\InvalidArgumentException;

covers(RecordingPendingProcess::class);

describe(RecordingPendingProcess::class, function (): void {
    it('records a background process that fails to start under its plain command line', function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);

        // A negative timeout makes Symfony refuse to build the process at all.
        expect(fn () => Process::timeout(-1)->start(['php', '-v']))->toThrow(InvalidArgumentException::class);

        $row = app(Eyepiece::class)->finalize()->rows('processes');

        expect($row)->toHaveCount(1)
            ->and($row[0])->toMatchArray(['command' => 'php -v', 'async' => true, 'successful' => false, 'exit_code' => null])
            ->and($row[0]['exception']['class'] ?? null)->toBe(InvalidArgumentException::class);
    });
});
