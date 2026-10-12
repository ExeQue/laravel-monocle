<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use ExeQue\Monocle\Dashboard\TimelinePresenter;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Editor\EditorLinks;
use ExeQue\Monocle\Editor\EditorSettings;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Http\Controllers\AssetController;
use ExeQue\Monocle\Http\Controllers\CapturesController;
use ExeQue\Monocle\Http\Controllers\DashboardController;
use ExeQue\Monocle\Http\Middleware\Authorize;
use ExeQue\Monocle\Lenses\Packages\Prism\TracedProvider;
use ExeQue\Monocle\MonocleServiceProvider;
use ExeQue\Monocle\Storage\StoreManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Tools\AgentTool;
use LogicException;
use Mockery;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Provider as PrismProvider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;
use Tests\Fixtures\Ai\Greeter;
use Tests\Fixtures\Ai\WeatherTool;
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

        it('shows the host of each capture in the list only when show_host is on', function (): void {
            $this->get('/_monocle')->assertDontSee('x-text="c.host"', false);

            config(['monocle.dashboard.show_host' => true]);

            $this->get('/_monocle')->assertSee('x-text="c.host"', false);
        });

        it('sends the names the tabs show for sections that have one', function (): void {
            $this->get('/_monocle')->assertViewHas('labels', fn (array $labels): bool => ($labels['model_changes'] ?? null) === 'Changes'
                && ! in_array(null, $labels, true));
        });

        it('renders the tab view of a section a lens presents beside its own', function (): void {
            $this->get('/_monocle')
                ->assertViewHas('tabs', fn (array $tabs): bool => $tabs['job'] === 'monocle::tabs.job')
                ->assertSee('monocle-panel-job', false);
        });

        it('shows paths relative to the project root, not to the deeper Testbench base path', function (): void {
            $this->get('/_monocle')->assertViewHas('paths', ['base' => dirname(__DIR__, 3)]);
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
        it('places the sections a listed lens provides right after its own tab', function (): void {
            Route::get('/runs-a-command', function () {
                \Illuminate\Support\Facades\Artisan::call('list');

                return 'ok';
            });

            $this->get('/runs-a-command')->assertOk();
            config(['monocle.dashboard.tabs' => [\ExeQue\Monocle\Lenses\RequestLens::class, \ExeQue\Monocle\Lenses\CommandLens::class]]);

            $keys = array_column($this->getJson('/_monocle/api/captures/'.latestCapture()->id)->json('tabs'), 'key');

            expect(array_slice($keys, 0, 2))->toBe(['request', 'commands']);
        });

        it('renders http calls, queries, validations, exceptions and dumps on the server', function (): void {
            Http::fake(['api.example.test/*' => Http::response(['ok' => true], 201)]);
            Route::get('/busy', function () {
                Http::withToken('secret-token-123')->post('https://api.example.test/orders', ['sku' => 'A1']);
                Validator::make(['name' => 'Ada'], ['name' => 'required|string'])->validate();
                DB::select('select ? as answer', [42]);
                report(new RuntimeException('outer', 0, new LogicException('inner')));
                ob_start();
                dump(['user' => 'Ada'], new \Tests\Fixtures\Models\Writer());
                ob_end_clean();

                return 'ok';
            });

            $this->get('/busy')->assertOk();
            $id = latestCapture()->id;

            $http = $this->getJson("/_monocle/api/captures/{$id}/rows/http/1/details")->assertOk()->json('html');
            $validation = $this->getJson("/_monocle/api/captures/{$id}/rows/validation/1/details")->assertOk()->json('html');
            $exception = $this->getJson("/_monocle/api/captures/{$id}/rows/exceptions/1/details")->assertOk()->json('html');
            $query = $this->getJson("/_monocle/api/captures/{$id}/rows/queries/1/details")->assertOk()->json('html');
            $dump = $this->getJson("/_monocle/api/captures/{$id}/rows/dumps/1/details")->assertOk()->json('html');

            expect($http)->toContain('https://api.example.test/orders', 'Request headers', 'data-monocle-cards', 'Bearer secr****')
                ->not->toContain('secret-token-123')
                ->and($validation)->toContain('name', 'required', 'Ada', 'passed')
                ->and($exception)->toContain('RuntimeException', 'outer', 'LogicException', 'inner', 'data-full')
                ->and($query)->toContain('Query with placeholders', 'select ? as answer', '?1', '42', 'Connection')
                ->and($dump)->toContain('<pre', '<span class="text-ok">Ada</span>', 'dump()');

            $object = $this->getJson("/_monocle/api/captures/{$id}/rows/dumps/2/details")->assertOk()->json('html');

            expect($object)->toContain('<span data-monocle-name data-full>Tests\\Fixtures\\Models\\Writer</span>');
        });

        it('renders agent runs with their tool calls and sub-agents, and each tool call, on the server', function (): void {
            Route::get('/agent', function () {
                $provider = Mockery::mock(TextProvider::class)->shouldIgnoreMissing();
                $provider->shouldReceive('name')->andReturn('openai');
                $prompt = fn (string $id, ?string $parent = null, ?string $tool = null): AgentPrompt => new AgentPrompt(new Greeter(), 'Weather in Aarhus?', [], $provider, 'gpt-test', null, $id, null, $parent, $tool);
                $agent = new Greeter();
                $subAgent = new AgentTool(new Greeter());

                event(new PromptingAgent('inv-1', $prompt('inv-1')));
                event(new InvokingTool('inv-1', 'tool-1', $agent, new WeatherTool(), ['city' => 'Aarhus']));
                event(new ToolInvoked('inv-1', 'tool-1', $agent, new WeatherTool(), ['city' => 'Aarhus'], '{"sky":"sunny"}', 42.0));
                event(new InvokingTool('inv-1', 'tool-2', $agent, $subAgent, ['task' => 'Say hi']));
                event(new PromptingAgent('inv-2', $prompt('inv-2', 'inv-1', 'tool-2')));
                event(new AgentPrompted('inv-2', $prompt('inv-2', 'inv-1', 'tool-2'), new AgentResponse('inv-2', 'Hi!', new TextUsage(7, 3), new Meta('openai', 'gpt-test'))));
                event(new ToolFailed('inv-1', 'tool-2', $agent, $subAgent, ['task' => 'Say hi'], new RuntimeException('no greeting'), 1.5));
                event(new AgentPrompted('inv-1', $prompt('inv-1'), new AgentResponse('inv-1', 'It is sunny.', new TextUsage(100, 20, 5, 2, 4), new Meta('openai', 'gpt-test'))));
                event(new PromptingAgent('inv-3', $prompt('inv-3')));
                event(new AgentFailed('inv-3', $prompt('inv-3'), new RuntimeException('gave up')));

                return 'ok';
            });

            $this->get('/agent')->assertOk();
            $id = latestCapture()->id;
            $details = fn (string $section, int $n): string => $this->getJson("/_monocle/api/captures/{$id}/rows/{$section}/{$n}/details")->assertOk()->json('html');

            $nested = $details('ai', 1);
            $outer = $details('ai', 2);
            $failed = $details('ai', 3);
            $tool = $details('ai_tools', 1);
            $broken = $details('ai_tools', 2);

            expect($outer)->toContain(Greeter::class, 'Weather in Aarhus?', 'It is sunny.', 'Cache read', 'Reasoning', 'Tool calls (2)', 'weather', 'sub-agent', '{&quot;city&quot;:&quot;Aarhus&quot;}')
                ->and($nested)->toContain('Hi!', 'Called by', 'Greeter')->not->toContain('Tool calls (')
                ->and($failed)->toContain('RuntimeException', 'gave up', 'none')
                ->and($tool)->toContain(WeatherTool::class, '&quot;city&quot;: &quot;Aarhus&quot;', '&quot;sky&quot;: &quot;sunny&quot;')
                ->and($broken)->toContain('Sub-agent', 'no greeting', 'Result');
        });

        it('renders Prism calls and their tool calls on the server', function (): void {
            $weather = new Tool()->as('weather')->for('Weather')->withStringParameter('city', 'City')
                ->using(fn (string $city): string => "sunny in {$city}");
            $provider = new class () extends PrismProvider {
                public function text(TextRequest $request): TextResponse
                {
                    foreach ($request->tools() as $tool) {
                        $tool->handle(city: 'Aarhus');
                    }

                    return TextResponseFake::make()->withText('done')->withUsage(new Usage(10, 5));
                }
            };

            Route::get('/prism', function () use ($weather, $provider) {
                Prism::fake([StructuredResponseFake::make()->withStructured(['name' => 'Ada'])->withUsage(new Usage(10, 5))]);
                Prism::structured()->using('anthropic', 'claude-test')
                    ->withSchema(new ObjectSchema('person', 'A person', [new StringSchema('name', 'The name')], ['name']))
                    ->withPrompt('Who wrote the first program?')
                    ->asStructured();
                new TracedProvider($provider, app(Eyepiece::class), null)
                    ->text(new TextRequest('m', 'scripted', [], 'Weather?', [], 3, null, null, null, [$weather], [], [], null));

                return 'ok';
            });

            $this->get('/prism')->assertOk();
            $id = latestCapture()->id;
            $details = fn (string $section, int $n): string => $this->getJson("/_monocle/api/captures/{$id}/rows/{$section}/{$n}/details")->assertOk()->json('html');

            expect($details('prism', 1))->toContain('structured', 'anthropic/', 'Who wrote the first program?', 'Structured output', '&quot;name&quot;: &quot;Ada&quot;')
                ->and($details('prism', 2))->toContain('Weather?', 'done', 'Tools offered', 'weather')
                ->and($details('prism_tools', 1))->toContain('weather', '&quot;city&quot;: &quot;Aarhus&quot;', 'sunny in Aarhus');
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

        it('lets a lens leave its totals and problems off its tab', function (): void {
            $presentation = \ExeQue\Monocle\Dashboard\Presentation::table()->withoutTotals()->withoutProblems();

            expect($presentation->toArray())->toMatchArray(['shows_totals' => false, 'shows_problems' => false])
                ->and(\ExeQue\Monocle\Dashboard\Presentation::table()->toArray())->toMatchArray(['shows_totals' => true, 'shows_problems' => true]);
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
                'shows_totals' => true,
                'shows_problems' => true,
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

        it('sends the capture\'s children with the job each ran for', function (): void {
            $job = new Capture('job-run', CaptureType::Job, microtime(true), parentId: $this->id);
            $job->status = 'processed';
            $job->sections['job'] = ['job_id' => 'uuid-1'];
            app(StoreManager::class)->store()->put($job);

            expect($this->getJson("/_monocle/api/captures/{$this->id}")->json('children'))
                ->toMatchArray([['id' => 'job-run', 'type' => 'job', 'name' => null, 'status' => 'processed', 'has_errors' => false, 'job_id' => 'uuid-1', 'started_at' => $job->startedAt]]);
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
