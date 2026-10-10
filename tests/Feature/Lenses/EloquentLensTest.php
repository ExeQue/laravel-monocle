<?php

declare(strict_types=1);

use ExeQue\Monocle\Enums\EntryType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\EloquentLens;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Models\Thing;

covers(EloquentLens::class);

describe(EloquentLens::class, function (): void {
    beforeEach(function (): void {
        DB::statement('create table things (id integer primary key autoincrement, name text, secret text)');
        app(Eyepiece::class)->start(EntryType::Request);
    });

    it('counts model events per class and records writes without hidden attributes', function (): void {
        $thing = Thing::create(['name' => 'a', 'secret' => 'hidden']);
        Thing::create(['name' => 'b', 'secret' => 'hidden']);
        Thing::query()->get();
        $thing->update(['name' => 'c']);
        $thing->delete();

        $entry = app(Eyepiece::class)->finalize();
        $counts = collect($entry->rows('models'))->keyBy('action');
        $changes = $entry->rows('model_changes');

        expect($counts['retrieved']['count'])->toBe(2)
            ->and($counts['created']['count'])->toBe(2)
            ->and($counts['updated']['count'])->toBe(1)
            ->and($counts['deleted']['count'])->toBe(1)
            ->and($counts['retrieved']['model'])->toBe(Thing::class)
            ->and(array_column($changes, 'action'))->toBe(['created', 'created', 'updated', 'deleted'])
            ->and($changes[0]['changes'])->toMatchArray(['name' => 'a'])
            ->and($changes[0]['changes'])->not->toHaveKey('secret')
            ->and($changes[2]['changes'])->toBe(['name' => 'c'])
            ->and($changes[3]['key'])->toBe($thing->id)
            ->and($changes[0]['origin']['file'])->toContain('EloquentLensTest.php');
    });

    it('summarizes retrieved totals and the busiest models', function (): void {
        Thing::create(['name' => 'a']);
        Thing::query()->get();
        Thing::query()->get();

        $summary = (new EloquentLens())->summarize(app(Eyepiece::class)->finalize());

        expect($summary['totals']['models_retrieved'])->toBe(2)
            ->and($summary['totals']['models_written'])->toBe(1)
            ->and($summary['totals']['models_top_retrieved'])->toBe([Thing::class => 2]);
    });

    it('writes nothing when no models were touched', function (): void {
        $entry = app(Eyepiece::class)->finalize();

        expect($entry->sections)->not->toHaveKey('models')
            ->and((new EloquentLens())->summarize($entry)['totals']['models_retrieved'])->toBe(0);
    });
});
