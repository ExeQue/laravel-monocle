<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\DumpLens;
use Symfony\Component\VarDumper\VarDumper;

covers(DumpLens::class);

describe(DumpLens::class, function (): void {
    beforeEach(function (): void {
        $this->seen = [];
        VarDumper::setHandler(function (mixed $value, ?string $label = null): void {
            $this->seen[] = [$value, $label];
        });

        $lens = app(DumpLens::class);
        $lens->boot($this->app, app(Eyepiece::class));

        app(Eyepiece::class)->start(CaptureType::Request);
    });

    afterEach(function (): void {
        VarDumper::setHandler(null);
    });

    it('records dumps and still forwards them to the previous dumper', function (): void {
        VarDumper::dump(['album' => 7], 'first');
        dump('plain');

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('dumps');

        expect($rows)->toHaveCount(2)
            ->and($rows[0]['value'])->toContain('"album" => 7')
            ->and($rows[0]['type'])->toBe('array')
            ->and($rows[0]['label'])->toBe('first')
            ->and($rows[0]['is_dd'])->toBeFalse()
            ->and($rows[0]['origin']['file'])->toContain('DumpLensTest.php')
            ->and($rows[1]['type'])->toBe('string')
            ->and($rows[1]['label'])->toBeNull()
            ->and($this->seen)->toHaveCount(2)
            ->and($this->seen[0])->toBe([['album' => 7], 'first']);

        $summary = app(DumpLens::class)->summarize($capture);

        expect($summary->totals())->toBe(['dumps' => 2, 'dd_calls' => 0])
            ->and($summary->warnings())->toBe([]);
    });

    it('flags dd() in the summary', function (): void {
        app(Eyepiece::class)->push('dumps', ['value' => '1', 'type' => 'int', 'label' => null, 'is_dd' => true]);

        $summary = app(DumpLens::class)->summarize(app(Eyepiece::class)->finalize());

        expect($summary->totals()['dd_calls'])->toBe(1)
            ->and($summary->warnings()[0])->toContain('dd() ended the process');
    });

    it('records nothing outside a capture', function (): void {
        app(Eyepiece::class)->reset();

        dump('outside');

        expect($this->seen)->toHaveCount(1);
    });
});
