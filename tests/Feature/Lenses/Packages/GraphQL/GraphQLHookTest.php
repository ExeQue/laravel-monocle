<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\GraphQL;

use ExeQue\Monocle\Exceptions\InvalidGraphQLHookException;
use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLLens;
use ExeQue\Monocle\Lenses\Packages\GraphQL\Lighthouse\LighthouseHook;
use Illuminate\Support\Facades\Route;
use stdClass;
use Tests\Fixtures\GraphQL\CustomHook;
use Tests\Fixtures\GraphQL\MissingPackageHook;
use Tests\Fixtures\GraphQL\OperationRan;
use Tests\TestCase;

covers(GraphQLLens::class, InvalidGraphQLHookException::class);

describe('GraphQL hooks', function (): void {
    $boot = function (array|\Illuminate\Support\Enumerable $hooks): void {
        TestCase::$bootConfig = ['monocle.lenses.'.GraphQLLens::class => ['hooks' => $hooks]];
        $this->refreshApplication();
    };

    it('records a server of your own through a hook, and leaves out the servers not listed', function () use ($boot): void {
        $boot->call($this, collect([CustomHook::class, LighthouseHook::class, MissingPackageHook::class]));
        Route::post('/custom', function () {
            event(new OperationRan('query Me { me(password: "hunter22hunter22") }'));
            event(new OperationRan('{ broken', [['message' => 'Syntax Error']], parsed: false));

            return 'ok';
        });

        $this->post('/custom')->assertOk();
        [$ran, $broken] = latestCapture()->rows('graphql');

        expect($ran)->toMatchArray(['server' => 'custom', 'schema' => 'public', 'type' => 'query', 'operation' => 'Me', 'status' => 'ok', 'query' => 'query Me { me(password: "hunt****") }'])
            ->and($broken)->toMatchArray(['server' => 'custom', 'type' => null, 'status' => 'failed', 'query' => '{ broken'])
            ->and($broken['errors'][0]['message'])->toBe('Syntax Error');

        $this->postJson('/rebing', ['query' => '{ hello }'])->assertOk();

        expect(latestCapture()->rows('graphql'))->toBe([]);
    });

    it('records nothing for an operation that failed while nothing was recorded', function () use ($boot): void {
        $boot->call($this, [CustomHook::class]);
        config(['monocle.except.paths' => ['*']]);
        Route::post('/custom', fn () => event(new OperationRan('{ broken', parsed: false)) ? 'ok' : 'ok');

        $this->post('/custom')->assertOk();

        expect(latestCapture())->toBeNull();
    });

    it('refuses a hook that is not a GraphQL hook', function () use ($boot): void {
        expect(fn () => $boot->call($this, [stdClass::class]))
            ->toThrow(InvalidGraphQLHookException::class, 'GraphQL hook [stdClass] must implement '.\ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLHook::class.'.');
    });
});
