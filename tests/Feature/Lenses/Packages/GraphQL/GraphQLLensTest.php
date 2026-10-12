<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\GraphQL;

use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLLens;
use ExeQue\Monocle\Lenses\Packages\GraphQL\Rebing\ExecutionMiddleware;

covers(GraphQLLens::class, ExecutionMiddleware::class, \ExeQue\Monocle\Lenses\Packages\GraphQL\Lighthouse\LighthouseHook::class, \ExeQue\Monocle\Lenses\Packages\GraphQL\Rebing\RebingHook::class, \ExeQue\Monocle\Lenses\Packages\GraphQL\RootFields::class, \ExeQue\Monocle\Lenses\Packages\GraphQL\Lighthouse\RootResolvers::class);

const GREET = 'query Greet($name: String) { hello(name: $name) }';

/**
 * @return list<array<string, mixed>>
 */
function graphqlRows(): array
{
    return latestCapture()->rows('graphql');
}

describe(GraphQLLens::class, function (): void {
    describe('with Lighthouse', function (): void {
        it('records an operation with its variables and a span', function (): void {
            $this->postJson('/graphql', ['query' => GREET, 'variables' => ['name' => 'Ada']])->assertJsonPath('data.hello', 'Hello Ada');

            $capture = latestCapture();
            $row = $capture->rows('graphql')[0];

            expect($row)->toMatchArray([
                'server' => 'lighthouse',
                'schema' => realpath(__DIR__.'/../../../../Fixtures/GraphQL/schema.graphql'),
                'type' => 'query',
                'operation' => 'Greet',
                'query' => GREET,
                'variables' => ['name' => 'Ada'],
                'status' => 'ok',
                'errors' => [],
            ])
                ->and($row['duration'])->toBeFloat()
                ->and($row)->not->toHaveKey('origin')
                ->and($capture->spansOfKind('graphql'))->toHaveCount(1)
                ->and($capture->spansOfKind('graphql')[0]->name)->toBe('query Greet')
                ->and($capture->spansOfKind('graphql')[0]->isOpen())->toBeFalse();
        });

        it('masks variables whose name matches the input pattern', function (): void {
            $this->postJson('/graphql', [
                'query' => 'mutation Login($email: String!, $password: String!) { login(email: $email, password: $password) }',
                'variables' => ['email' => 'ada@example.com', 'password' => 'hunter22hunter22'],
            ])->assertJsonPath('data.login', true);

            expect(graphqlRows()[0])->toMatchArray([
                'type' => 'mutation',
                'variables' => ['email' => 'ada@example.com', 'password' => 'hunt****'],
            ]);
        });

        it('keeps the exception a resolver threw behind the error', function (): void {
            $this->postJson('/graphql', ['query' => '{ boom }'])->assertOk();

            $row = graphqlRows()[0];

            expect($row['status'])->toBe('failed')
                ->and($row['operation'])->toBeNull()
                ->and($row['errors'][0]['path'])->toBe(['boom'])
                ->and($row['errors'][0]['locations'])->toBe([['line' => 1, 'column' => 3]])
                ->and($row['errors'][0]['exception']['message'])->toBe('resolver exploded');
        });

        it('records an operation that fails validation and never ends', function (): void {
            $this->postJson('/graphql', ['query' => 'query Broken { nope nada }'])->assertOk();

            $capture = latestCapture();
            $row = $capture->rows('graphql')[0];

            expect($row)->toMatchArray(['type' => 'query', 'operation' => 'Broken', 'status' => 'failed'])
                ->and($row['errors'])->toHaveCount(2)
                ->and($row['errors'][0]['message'])->toContain('Cannot query field "nope"')
                ->and($row['errors'][0]['exception'])->toBeNull()
                ->and($capture->spansOfKind('graphql')[0]->isOpen())->toBeFalse()
                ->and(app(GraphQLLens::class)->summarize($capture)->errors)->toBe([
                    'GraphQL query Broken failed: '.$row['errors'][0]['message'].' (+1 more)',
                ]);
        });

        it('records an operation that does not parse', function (): void {
            $this->postJson('/graphql', ['query' => '{ hello(', 'operationName' => 'Half', 'variables' => ['name' => 'Ada']])->assertOk();

            $row = graphqlRows()[0];

            expect($row)->toMatchArray([
                'server' => 'lighthouse',
                'type' => null,
                'operation' => 'Half',
                'query' => '{ hello(',
                'variables' => ['name' => 'Ada'],
                'status' => 'failed',
            ])
                ->and($row['errors'][0]['message'])->toContain('Syntax Error')
                ->and($row['duration'])->toBeFloat();
        });

        it('records each operation of a batch', function (): void {
            $this->postJson('/graphql', [
                ['query' => GREET],
                ['query' => '{ nope }'],
            ])->assertOk();

            $capture = latestCapture();
            [$ok, $invalid] = $capture->rows('graphql');

            expect($ok['status'])->toBe('ok')
                ->and($invalid)->toMatchArray(['operation' => null, 'status' => 'failed', 'errors' => null])
                ->and(app(GraphQLLens::class)->summarize($capture)->errors)->toBe(['GraphQL query (anonymous) failed.']);
        });

        it('links each root field to its resolver and to where the schema defines it', function (): void {
            $this->postJson('/graphql', ['query' => 'query All { hello ...Rest } fragment Rest on Query { ... on Query { ping shout } __typename }'])->assertOk();

            $schema = realpath(__DIR__.'/../../../../Fixtures/GraphQL/schema.graphql');

            expect(graphqlRows()[0]['fields'])->toBe([
                ['field' => 'hello', 'resolver' => 'Tests\\Fixtures\\GraphQL\\Resolvers@hello', 'definition' => $schema.':4'],
                ['field' => 'ping', 'resolver' => 'Tests\\Fixtures\\GraphQL\\Queries\\Ping@__invoke', 'definition' => $schema.':10'],
                ['field' => 'shout', 'resolver' => \Nuwave\Lighthouse\Schema\Directives\MethodDirective::class.'@resolveField', 'definition' => $schema.':11'],
            ]);

            $this->postJson('/graphql', ['query' => 'mutation { login(email: "a", password: "b") nope }'])->assertOk();

            expect(graphqlRows()[0]['fields'])->toBe([
                ['field' => 'login', 'resolver' => 'Tests\\Fixtures\\GraphQL\\Resolvers@login', 'definition' => realpath(__DIR__.'/../../../../Fixtures/GraphQL/mutations.graphql').':2'],
            ]);

            $this->postJson('/graphql', ['query' => '{ ...Missing }'])->assertOk();

            expect(graphqlRows()[0]['fields'])->toBeNull();

            $this->postJson('/graphql', ['query' => 'subscription { hello }'])->assertOk();

            expect(graphqlRows()[0]['fields'])->toBeNull();
        });
    });

    describe('with rebing', function (): void {
        it('records an operation with its schema', function (): void {
            $this->postJson('/rebing', ['query' => GREET, 'variables' => ['name' => 'Ada']])->assertJsonPath('data.hello', 'Hello Ada');

            $capture = latestCapture();
            $summary = app(GraphQLLens::class)->summarize($capture);

            expect($capture->rows('graphql')[0])->toMatchArray([
                'server' => 'rebing',
                'schema' => 'default',
                'type' => 'query',
                'operation' => 'Greet',
                'variables' => ['name' => 'Ada'],
                'status' => 'ok',
            ])
                ->and($capture->spansOfKind('graphql')[0]->name)->toBe('query Greet')
                ->and($summary->totals['graphql_operations'])->toBe(1)
                ->and($summary->totals['graphql_ms'])->toBeFloat()
                ->and($summary->errors)->toBe([]);
        });

        it('goes first in a schema with execution middleware of its own', function (): void {
            $this->postJson('/rebing/own', ['query' => GREET])->assertJsonPath('data.hello', 'Hello world');

            expect(config('graphql.schemas.own.execution_middleware')[0])->toBe(ExecutionMiddleware::class)
                ->and(graphqlRows()[0]['schema'])->toBe('own');
        });

        it('links each root field to the field class that resolves it', function (): void {
            $this->postJson('/rebing', ['query' => '{ hello boom nope }'])->assertOk();

            expect(graphqlRows()[0]['fields'])->toBe([
                ['field' => 'hello', 'resolver' => \Tests\Fixtures\GraphQL\HelloQuery::class.'@resolve', 'definition' => null],
                ['field' => 'boom', 'resolver' => \Tests\Fixtures\GraphQL\BoomQuery::class.'@resolve', 'definition' => null],
            ]);

            $this->postJson('/rebing', ['query' => 'mutation { hello }'])->assertOk();

            expect(graphqlRows()[0]['fields'])->toBeNull();
        });

        it('records validation errors and resolver exceptions', function (): void {
            $this->postJson('/rebing', ['query' => '{ nope }'])->assertOk();

            expect(graphqlRows()[0]['errors'][0]['message'])->toContain('Cannot query field "nope"');

            $this->postJson('/rebing', ['query' => '{ boom }'])->assertOk();

            expect(graphqlRows()[0]['errors'][0]['exception']['message'])->toBe('resolver exploded');
        });

        it('records an operation that throws out of the middleware', function (): void {
            $this->postJson('/rebing', ['query' => '{ hello(']);

            $row = graphqlRows()[0];

            expect($row)->toMatchArray(['type' => null, 'query' => '{ hello(', 'status' => 'failed'])
                ->and($row['errors'][0]['exception']['message'])->toContain('Syntax Error');
        });

        it('fills in an automatic persisted query once it is looked up', function (): void {
            config(['graphql.apq.enable' => true]);
            $persisted = ['persistedQuery' => ['version' => 1, 'sha256Hash' => hash('sha256', GREET)]];

            $this->postJson('/rebing', ['query' => GREET, 'extensions' => $persisted])->assertOk();
            $this->postJson('/rebing', ['extensions' => $persisted])->assertJsonPath('data.hello', 'Hello world');

            expect(graphqlRows()[0])->toMatchArray(['type' => 'query', 'operation' => 'Greet', 'query' => GREET]);
            expect(latestCapture()->spansOfKind('graphql')[0]->name)->toBe('graphql');
        });
    });

    it('records nothing while nothing is recorded', function (): void {
        config(['monocle.except.paths' => ['*']]);

        $this->postJson('/graphql', ['query' => GREET])->assertJsonPath('data.hello', 'Hello world');
        $this->postJson('/graphql', ['query' => '{ hello('])->assertOk();
        $this->postJson('/rebing', ['query' => GREET])->assertJsonPath('data.hello', 'Hello world');

        expect(latestCapture())->toBeNull();
    });

    it('has nothing to summarize without operations', function (): void {
        $this->get('/nothing-here');

        expect(app(GraphQLLens::class)->summarize(latestCapture())->totals)->toBe([]);
    });

    it('renders the details of an operation with its fields, highlighted query and errors', function (): void {
        \Illuminate\Support\Facades\Gate::define('viewMonocle', fn (mixed $user = null): bool => true);
        $this->postJson('/graphql', ['query' => 'query Mixed($name: String) { hello(name: $name) boom }', 'variables' => ['name' => 'Ada']])->assertOk();

        $id = latestCapture()->id;
        $html = $this->getJson("/_monocle/api/captures/{$id}/rows/graphql/1/details")->assertOk()->json('html');

        expect($html)->toContain(
            'Tests\\Fixtures\\GraphQL\\Resolvers@hello',
            'schema.graphql:4',
            'data-monocle-graphql',
            "query Mixed(\$name: String) {\n  hello(name: \$name)\n  boom\n}",
            '&quot;name&quot;: &quot;Ada&quot;',
            'Errors (1)',
            'path boom',
            'line 1:49',
            'resolver exploded',
        );

        $this->postJson('/rebing', ['query' => '{ hello }'])->assertOk();

        $id = latestCapture()->id;
        $html = $this->getJson("/_monocle/api/captures/{$id}/rows/graphql/1/details")->assertOk()->json('html');

        expect($html)->toContain(\Tests\Fixtures\GraphQL\HelloQuery::class.'@resolve')
            ->not->toContain('Definition', 'Variables', 'Errors', 'ping');
    });

    it('shows a GraphQL tab with the query, variables and errors in the details', function (): void {
        $presentation = app(GraphQLLens::class)->presentation();

        expect($presentation->tabView)->toBe('monocle::tabs.graphql')
            ->and($presentation->label)->toBe('GraphQL')
            ->and(array_keys($presentation->columns))->toBe(['type', 'operation', 'status', 'schema', 'server', 'duration'])
            ->and($presentation->detailsOnly)->toBe(['query', 'variables', 'fields', 'errors'])
            ->and($presentation->detailsView)->toBe('monocle::details.graphql');
    });
});
