<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\DumpLens;
use RuntimeException;
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
            ->and(implode('', array_column($rows[0]['tokens'], 1)))->toBe($rows[0]['value'])
            ->and($rows[0]['type'])->toBe('array')
            ->and($rows[0]['label'])->toBe('first')
            ->and($rows[0]['is_dd'])->toBeFalse()
            ->and($rows[0]['origin']['file'])->toContain('DumpLensTest.php')
            ->and($rows[1]['type'])->toBe('string')
            ->and($rows[1]['label'])->toBeNull()
            ->and($this->seen)->toHaveCount(2)
            ->and($this->seen[0])->toBe([['album' => 7], 'first']);

        $summary = app(DumpLens::class)->summarize($capture);

        expect($summary->totals)->toBe(['dumps' => 2, 'dd_calls' => 0])
            ->and($summary->warnings)->toBe([]);
    });

    it('flags dd() in the summary', function (): void {
        app(Eyepiece::class)->push('dumps', ['value' => '1', 'type' => 'int', 'label' => null, 'is_dd' => true]);

        $summary = app(DumpLens::class)->summarize(app(Eyepiece::class)->finalize());

        expect($summary->totals['dd_calls'])->toBe(1)
            ->and($summary->warnings[0])->toContain('dd() ended the process');
    });

    it('records nothing outside a capture', function (): void {
        app(Eyepiece::class)->reset();

        dump('outside');

        expect($this->seen)->toHaveCount(1);
    });
    it('flags a dump made by the global dd() helper', function (): void {
        VarDumper::setHandler(function (): void {
            throw new RuntimeException('stop before dd() exits');
        });
        app(DumpLens::class)->boot($this->app, app(Eyepiece::class));

        try {
            dd('dying');
        } catch (RuntimeException) {
        }

        $rows = app(Eyepiece::class)->finalize()->rows('dumps');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['is_dd'])->toBeTrue();
    });

    it('falls back to the default VarDumper output when no dumper was registered before it', function (): void {
        $defaultOutput = \Symfony\Component\VarDumper\Dumper\CliDumper::$defaultOutput;
        \Symfony\Component\VarDumper\Dumper\CliDumper::$defaultOutput = 'php://output';
        VarDumper::setHandler(null);
        app(DumpLens::class)->boot($this->app, app(Eyepiece::class));

        ob_start();

        try {
            dump('first-default');
            dump('second-default');
        } finally {
            $output = (string) ob_get_clean();
            \Symfony\Component\VarDumper\Dumper\CliDumper::$defaultOutput = $defaultOutput;
        }

        expect($output)->toContain('first-default')->toContain('second-default')
            ->and(app(Eyepiece::class)->finalize()->rows('dumps'))->toHaveCount(2);
    });

    it('summarizes nothing without dumps', function (): void {
        expect(app(DumpLens::class)->summarize(app(Eyepiece::class)->finalize()))->toEqual(\ExeQue\Monocle\Data\Summary::make());
    });

    it('describes itself for the agent and the dashboard', function (): void {
        $lens = app(DumpLens::class);

        expect($lens->advice())->toContain('dump() and dd()')
            ->and($lens->presentation()->tabView)->toBe('monocle::tabs.dumps')
            ->and($lens->presentation()->detailsView)->toBe('monocle::details.dump');
    });
});
