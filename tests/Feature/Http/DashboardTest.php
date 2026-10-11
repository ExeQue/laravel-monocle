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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use LogicException;
use RuntimeException;
use Tests\Fixtures\PaymentsLens;
use Tests\TestCase;

covers(\ExeQue\Monocle\Dashboard\Presentation::class, \ExeQue\Monocle\Support\Origin::class, DashboardController::class, CapturesController::class, AssetController::class, Authorize::class, TimelinePresenter::class, MonocleServiceProvider::class);

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

    describe('built-in detail views', function (): void {
        it('renders http calls, queries, validations and exceptions on the server', function (): void {
            Http::fake(['api.example.test/*' => Http::response(['ok' => true], 201)]);
            Route::get('/busy', function () {
                Http::withToken('secret-token-123')->post('https://api.example.test/orders', ['sku' => 'A1']);
                Validator::make(['name' => 'Ada'], ['name' => 'required|string'])->validate();
                DB::select('select ? as answer', [42]);
                report(new RuntimeException('outer', 0, new LogicException('inner')));

                return 'ok';
            });

            $this->get('/busy')->assertOk();
            $id = latestCapture()->id;

            $http = $this->getJson("/_monocle/api/captures/{$id}/rows/http/1/details")->assertOk()->json('html');
            $validation = $this->getJson("/_monocle/api/captures/{$id}/rows/validation/1/details")->assertOk()->json('html');
            $exception = $this->getJson("/_monocle/api/captures/{$id}/rows/exceptions/1/details")->assertOk()->json('html');
            $query = $this->getJson("/_monocle/api/captures/{$id}/rows/queries/1/details")->assertOk()->json('html');

            expect($http)->toContain('https://api.example.test/orders', 'Request headers', 'data-monocle-cards', 'Bearer secr****')
                ->not->toContain('secret-token-123')
                ->and($validation)->toContain('name', 'required', 'Ada', 'passed')
                ->and($exception)->toContain('RuntimeException', 'outer', 'LogicException', 'inner', 'data-full')
                ->and($query)->toContain('Query with placeholders', 'select ? as answer', '?1', '42', 'Connection');
        });
    });

    describe('lenses that present their section', function (): void {
        beforeEach(function (): void {
            TestCase::$bootConfig = ['monocle.lenses.'.PaymentsLens::class => true];
            $this->refreshApplication();
            Gate::define('viewMonocle', fn (mixed $user = null): bool => true);
            View::addLocation(dirname(__DIR__, 2).'/Fixtures/views');

            Route::get('/checkout', function () {
                event('payments.charged', [['amount' => 499, 'customer' => 'ada', 'status' => 'paid', 'card' => '4242', 'extra' => 'x']]);

                return 'ok';
            });

            $this->get('/checkout')->assertOk();
            $this->id = latestCapture()->id;
        });

        afterEach(function (): void {
            TestCase::$bootConfig = [];
        });

        it('orders tabs by monocle.dashboard.tabs, lens classes or section keys', function (): void {
            config(['monocle.dashboard.tabs' => [PaymentsLens::class, 'cache', \ExeQue\Monocle\Lenses\ExceptionsLens::class]]);

            $keys = array_column($this->getJson("/_monocle/api/captures/{$this->id}")->json('tabs'), 'key');
            $listed = array_values(array_intersect($keys, ['payments', 'cache', 'exceptions']));

            expect($keys[0])->toBe('payments')
                ->and(array_slice($keys, 0, count($listed)))->toBe($listed);
        });

        it('sends how the section is shown with the capture', function (): void {
            expect($this->getJson("/_monocle/api/captures/{$this->id}")->json('presentations.payments'))->toBe([
                'label' => 'Payments',
                'columns' => ['amount', 'customer', 'status'],
                'labels' => ['amount' => 'Amount (DKK)'],
                'details_only' => ['card'],
                'groupings' => [['customer', 'by customer']],
                'details_view' => true,
                'includes' => [],
                'formats' => [],
                'highlights' => [],
            ]);
        });

        it('renders an expanded row with the lens view', function (): void {
            $this->getJson("/_monocle/api/captures/{$this->id}/rows/payments/1/details")
                ->assertOk()
                ->assertJsonPath('html', "<p>Charged 499 to ada (GET /checkout)</p>\n");

            $this->getJson("/_monocle/api/captures/{$this->id}/rows/payments/2/details")->assertNotFound();
            expect(latestCapture()->rows('payments')[0]['origin']['file'])->toEndWith('DashboardTest.php');
            $this->getJson("/_monocle/api/captures/{$this->id}/rows/request/1/details")->assertNotFound();
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
