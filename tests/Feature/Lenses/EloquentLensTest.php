<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\EloquentLens;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Fixtures\Models\Thing;

covers(EloquentLens::class);

describe(EloquentLens::class, function (): void {
    beforeEach(function (): void {
        DB::statement('create table things (id integer primary key autoincrement, name text, secret text)');
        app(Eyepiece::class)->start(CaptureType::Request);
    });

    it('counts model events per class and records writes without hidden attributes', function (): void {
        $thing = Thing::create(['name' => 'a', 'secret' => 'hidden']);
        Thing::create(['name' => 'b', 'secret' => 'hidden']);
        Thing::query()->get();
        $thing->update(['name' => 'c']);
        $thing->delete();

        $capture = app(Eyepiece::class)->finalize();
        $counts = collect($capture->rows('models'))->keyBy('action');
        $changes = $capture->rows('model_changes');

        expect($counts['retrieved']['count'])->toBe(2)
            ->and($counts['created']['count'])->toBe(2)
            ->and($counts['updated']['count'])->toBe(1)
            ->and($counts['deleted']['count'])->toBe(1)
            ->and($counts['retrieved']['model'])->toBe(Thing::class)
            ->and(array_column($changes, 'action'))->toBe(['created', 'created', 'updated', 'deleted'])
            ->and($changes[0]['changes'])->toContain(['field' => 'name', 'from' => null, 'to' => 'a'])
            ->and(array_column($changes[0]['changes'], 'field'))->not->toContain('secret')
            ->and($changes[2]['changes'])->toBe([['field' => 'name', 'from' => 'a', 'to' => 'c']])
            ->and($changes[3]['changes'])->toContain(['field' => 'name', 'from' => 'c', 'to' => null])
            ->and($changes[3]['key'])->toBe($thing->id)
            ->and($changes[0]['origin']['file'])->toContain('EloquentLensTest.php');
    });

    it('flags writes made inside a transaction that was rolled back', function (): void {
        Thing::create(['name' => 'kept']);

        try {
            DB::transaction(function (): void {
                Thing::create(['name' => 'never saved']);

                throw new RuntimeException('undo');
            });
        } catch (RuntimeException) {
        }

        $changes = app(Eyepiece::class)->finalize()->rows('model_changes');

        expect($changes[0])->not->toHaveKey('rolled_back')
            ->and($changes[0]['connection'])->toBe(DB::getDefaultConnection())
            ->and($changes[1]['rolled_back'])->toBeTrue();
    });

    it('summarizes models retrieved, created, updated and deleted, and the busiest ones', function (): void {
        $thing = Thing::create(['name' => 'a']);
        $thing->update(['name' => 'b']);
        Thing::create(['name' => 'c'])->delete();
        Thing::query()->get();
        Thing::query()->get();

        $summary = app(EloquentLens::class)->summarize(app(Eyepiece::class)->finalize());

        expect($summary->totals)->toMatchArray(['models_retrieved' => 2, 'models_created' => 2, 'models_updated' => 1, 'models_deleted' => 1])
            ->and($summary->totals['models_top_retrieved'])->toBe([Thing::class => 2]);
    });

    it('writes nothing when no models were touched', function (): void {
        $capture = app(Eyepiece::class)->finalize();

        expect($capture->sections)->not->toHaveKey('models')
            ->and(app(EloquentLens::class)->summarize($capture)->totals['models_retrieved'])->toBe(0);
    });
    it('flags only writes on the connection of the rolled back transaction', function (): void {
        config(['database.connections.other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        app(Eyepiece::class)->span('unrelated')->start()->stop();

        try {
            DB::connection('other')->transaction(function (): void {
                Thing::create(['name' => 'on the default connection']);

                throw new RuntimeException('undo');
            });
        } catch (RuntimeException) {
        }

        $changes = app(Eyepiece::class)->finalize()->rows('model_changes');

        expect($changes)->toHaveCount(1)
            ->and($changes[0])->not->toHaveKey('rolled_back');
    });

    it('flags nothing when a rolled back transaction wrote no models', function (): void {
        try {
            DB::transaction(function (): void {
                throw new RuntimeException('undo');
            });
        } catch (RuntimeException) {
        }

        expect(app(Eyepiece::class)->finalize()->sections)->not->toHaveKey('model_changes');
    });

    it('ignores model events without a model and while no capture is recording', function (): void {
        event('eloquent.created: '.Thing::class, ['not a model']);

        app(Eyepiece::class)->discard();
        Thing::create(['name' => 'unrecorded']);

        expect(app(Eyepiece::class)->active()->rows('model_changes'))->toBe([]);
    });

    it('counts restores as updates and force deletes as deletes', function (): void {
        $capture = app(Eyepiece::class)->finalize();
        $capture->sections['models'] = [
            ['model' => Thing::class, 'action' => 'restored', 'count' => 2],
            ['model' => Thing::class, 'action' => 'forceDeleted', 'count' => 3],
        ];

        expect(app(EloquentLens::class)->summarize($capture)->totals)->toMatchArray(['models_updated' => 2, 'models_deleted' => 3]);
    });

    it('describes itself for the agent and the dashboard', function (): void {
        $lens = app(EloquentLens::class);
        $changes = $lens->presentation()->included['model_changes'] ?? null;

        expect($lens->description())->toContain('model_changes')
            ->and($lens->advice())->toStartWith('Look here to see how many models')
            ->and($lens->presentation()->tabView)->toBe('monocle::tabs.models')
            ->and($lens->presentation()->includes)->toBe(['model_changes'])
            ->and($changes?->highlights)->toBe(['rolled_back' => 'warn']);
    });
});
