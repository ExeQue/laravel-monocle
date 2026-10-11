<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use ExeQue\Monocle\Dashboard\TimelinePresenter;
use ExeQue\Monocle\Editor\EditorLinks;
use ExeQue\Monocle\Editor\EditorSettings;
use ExeQue\Monocle\Http\Controllers\AssetController;
use ExeQue\Monocle\Http\Controllers\CapturesController;
use ExeQue\Monocle\Http\Controllers\DashboardController;
use ExeQue\Monocle\Http\Middleware\Authorize;
use ExeQue\Monocle\MonocleServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

covers(DashboardController::class, CapturesController::class, AssetController::class, Authorize::class, TimelinePresenter::class, MonocleServiceProvider::class);

describe('dashboard', function (): void {
    beforeEach(function (): void {
        Gate::define('viewMonocle', fn (mixed $user = null): bool => true);
    });

    describe('access', function (): void {
        it('is only open in the local environment by default', function (): void {
            TestCase::$bootConfig = [];
            $this->refreshApplication();

            $this->get('/_monocle')->assertForbidden();
            $this->getJson('/_monocle/api/captures')->assertForbidden();

            $this->app['env'] = 'local';

            $this->get('/_monocle')->assertOk();
        });

        it('serves the dashboard and its compiled assets', function (): void {
            $this->get('/_monocle')
                ->assertOk()
                ->assertSee('/_monocle/assets/monocle.js?v=', false)
                ->assertSee('/_monocle/assets/monocle.css?v=', false);

            $this->get('/_monocle/assets/monocle.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
            $this->get('/_monocle/assets/monocle.js')->assertOk();
            $this->get('/_monocle/assets/secret.php')->assertNotFound();
        });

        it('lives under the configured path', function (): void {
            TestCase::$bootConfig = ['monocle.dashboard.path' => 'tools/profiler'];
            $this->refreshApplication();
            Gate::define('viewMonocle', fn (mixed $user = null): bool => true);

            $this->get('/tools/profiler')->assertOk();
            $this->getJson('/tools/profiler/api/captures')->assertOk();
            $this->get('/_monocle')->assertNotFound();
        });

        it('can be turned off', function (): void {
            TestCase::$bootConfig = ['monocle.dashboard.enabled' => false];
            $this->refreshApplication();
            Gate::define('viewMonocle', fn (mixed $user = null): bool => true);

            $this->get('/_monocle')->assertNotFound();
            $this->getJson('/_monocle/api/captures')->assertNotFound();
        });

        it('is never recorded itself', function (): void {
            $this->get('/_monocle')->assertOk();
            $this->getJson('/_monocle/api/captures')->assertOk();

            expect(latestCapture())->toBeNull();
        });
    });

    describe('api', function (): void {
        beforeEach(function (): void {
            DB::statement('create table things (id integer primary key, name text)');
            Route::get('/things', function () {
                Cache::put('thing', 'value', 60);
                DB::table('things')->where('id', 1)->first();

                return 'ok';
            });

            $this->get('/things')->assertOk();
            $this->id = latestCapture()->id;
        });

        it('lists captures', function (): void {
            $this->getJson('/_monocle/api/captures')
                ->assertOk()
                ->assertJsonPath('total', 1)
                ->assertJsonPath('rows.0.name', 'GET /things');
        });

        it('shows a capture with its summary and a timeline with queries placed under the span they ran in', function (): void {
            $result = $this->getJson("/_monocle/api/captures/{$this->id}")->assertOk()->json();

            $find = function (array $nodes, string $kind) use (&$find): ?array {
                foreach ($nodes as $node) {
                    if ($node['kind'] === $kind) {
                        return $node;
                    }

                    if (($found = $find($node['children'], $kind)) !== null) {
                        return $found;
                    }
                }

                return null;
            };

            $handle = collect($result['timeline'])->firstWhere('name', 'handle');
            $query = $find($result['timeline'], 'db');

            expect($result['summary']['name'])->toBe('GET /things')
                ->and($result['context']['route'])->toBeNull()
                ->and($result['context']['action'])->toStartWith('Closure (')->toContain('DashboardTest.php:')
                ->and($result['editor'])->toBeFalse()
                ->and($handle)->not->toBeNull()
                ->and($find($handle['children'], 'db'))->not->toBeNull()
                ->and($query['name'])->toContain('select * from "things"')
                ->and($query['d']['sql'])->toContain('"id" = 1')
                ->and($query['d']['origin']['link'])->toBeNull()
                ->and($find($result['timeline'], 'cache')['name'])->toBe('WRITE thing');
        });

        it('links files and classes when an editor is set', function (): void {
            $this->app->instance(EditorLinks::class, new EditorLinks(new EditorSettings('vscode'), '/app'));

            $rows = $this->getJson("/_monocle/api/captures/{$this->id}/rows/queries")->assertOk()->json('rows');
            $groups = $this->getJson("/_monocle/api/captures/{$this->id}/queries?group_by=caller")->assertOk()->json('rows');

            expect($rows[0]['origin']['link'])->toStartWith('vscode://file/')
                ->and($groups[0]['origin']['link'])->toStartWith('vscode://file/')
                ->and($this->getJson("/_monocle/api/captures/{$this->id}")->json('editor'))->toBeTrue();
        });

        it('pages rows, traces and clears', function (): void {
            $this->getJson("/_monocle/api/captures/{$this->id}/rows/cache?limit=1")->assertOk()->assertJsonPath('total', 1);
            $this->getJson("/_monocle/api/captures/{$this->id}/trace")->assertOk()->assertJsonPath('captures.0.id', $this->id);
            $this->getJson('/_monocle/api/captures/missing')->assertNotFound();
            $this->getJson('/_monocle/api/captures/missing/trace')->assertNotFound();

            $this->deleteJson('/_monocle/api/captures')->assertOk()->assertJsonPath('cleared', true);

            expect(latestCapture())->toBeNull();
        });
    });
});
