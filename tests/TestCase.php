<?php

declare(strict_types=1);

namespace Tests;

use ExeQue\Monocle\MonocleServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Laravel\Ai\AiServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected string $monoclePath;

    /**
     * Config applied before the providers boot; set it and call
     * refreshApplication() to test boot-time behaviour.
     *
     * @var array<string, mixed>
     */
    public static array $bootConfig = [];

    /**
     * @return array<int, class-string>
     */
    protected function setUp(): void
    {
        parent::setUp();

        // A developer's own .monocle.json in the Testbench app must not change the tests.
        $this->app->instance(\ExeQue\Monocle\Editor\EditorLinks::class, new \ExeQue\Monocle\Editor\EditorLinks(null, $this->app->basePath()));
    }

    protected function getPackageProviders($app): array
    {
        return [
            McpServiceProvider::class,
            AiServiceProvider::class,
            \Prism\Prism\PrismServiceProvider::class,
            LivewireServiceProvider::class,
            \RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
            \Kirschbaum\PowerJoins\PowerJoinsServiceProvider::class,
            \Filament\Support\SupportServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\Schemas\SchemasServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            \Filament\FilamentServiceProvider::class,
            \Tests\Fixtures\Filament\AdminPanelProvider::class,
            \Laravel\Pennant\PennantServiceProvider::class,
            \Spatie\ResponseCache\ResponseCacheServiceProvider::class,
            \Spatie\MediaLibrary\MediaLibraryServiceProvider::class,
            \Laravel\Sanctum\SanctumServiceProvider::class,
            \Laravel\Fortify\FortifyServiceProvider::class,
            \Laravel\Folio\FolioServiceProvider::class,
            \Inertia\ServiceProvider::class,
            \Nuwave\Lighthouse\LighthouseServiceProvider::class,
            \Rebing\GraphQL\GraphQLServiceProvider::class,
            \Spatie\EventSourcing\EventSourcingServiceProvider::class,
            MonocleServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $this->monoclePath = $app->storagePath('monocle-tests-'.bin2hex(random_bytes(4)));

        $app['config']->set('monocle.enabled', true);
        $app['config']->set('monocle.throw_failures', true);
        $app['config']->set('monocle.storage.file.path', $this->monoclePath);
        $app['config']->set('monocle.lenses.'.\ExeQue\Monocle\Lenses\FilesystemLens::class, ['disks' => ['spy', 'wild-*']]);
        $app['config']->set('monocle.storage.limit', 10);
        $app['config']->set('lighthouse.schema_path', __DIR__.'/Fixtures/GraphQL/schema.graphql');
        $app['config']->set('lighthouse.schema_cache.enable', false);
        $app['config']->set('lighthouse.query_cache.enable', false);
        $app['config']->set('lighthouse.namespaces.queries', 'Tests\\Fixtures\\GraphQL\\Queries');
        $app['config']->set('lighthouse.namespaces.mutations', ['App\\GraphQL\\Mutations', 'Tests\\Fixtures\\GraphQL']);
        $app['config']->set('graphql.route.prefix', 'rebing');
        $app['config']->set('event-sourcing.auto_discover_projectors_and_reactors', []);
        $app['config']->set('event-sourcing.projectors', [\Tests\Fixtures\EventSourcing\BalanceProjector::class, \Tests\Fixtures\EventSourcing\QueuedProjector::class]);
        $app['config']->set('event-sourcing.reactors', [\Tests\Fixtures\EventSourcing\WelcomeReactor::class]);
        $app['config']->set('event-sourcing.catch_exceptions', true);
        $app['config']->set('graphql.schemas', [
            'default' => ['query' => [\Tests\Fixtures\GraphQL\HelloQuery::class, \Tests\Fixtures\GraphQL\BoomQuery::class]],
            'own' => [
                'query' => [\Tests\Fixtures\GraphQL\HelloQuery::class],
                'execution_middleware' => [\Rebing\GraphQL\Support\ExecutionMiddleware\ValidateOperationParamsMiddleware::class],
            ],
        ]);
        foreach (self::$bootConfig as $key => $value) {
            $app['config']->set($key, $value);
        }

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->monoclePath);
        self::$bootConfig = [];

        parent::tearDown();
    }
}
