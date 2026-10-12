<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\GraphQL;

use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLCapture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

covers(GraphQLCapture::class, \ExeQue\Monocle\Lenses\RequestLens::class);

/**
 * @return list<array<string, mixed>>
 */
function graphqlCards(): array
{
    $capture = latestCapture()->section('request')['response_capture'];

    expect($capture['capture'])->toBe(GraphQLCapture::class);

    return $capture['cards'];
}

describe(GraphQLCapture::class, function (): void {
    it('shows a Lighthouse operation with its variables and data', function (): void {
        $this->postJson('/graphql', [
            'query' => 'mutation Login($email: String!, $password: String!) { login(email: $email, password: $password) }',
            'variables' => ['email' => 'ada@example.com', 'password' => 'hunter22hunter22'],
        ])->assertOk();

        expect(graphqlCards())->toBe([[
            'title' => 'mutation Login',
            'subtitle' => null,
            'items' => [
                'query' => "mutation Login(\$email: String!, \$password: String!) {\n  login(email: \$email, password: \$password)\n}",
                'variables' => ['email' => 'ada@example.com', 'password' => 'hunt****'],
                'data' => ['login' => true],
            ],
            'formats' => ['query' => 'graphql'],
        ]]);
    });

    it('masks values written into the query everywhere it is recorded, and formats it in the payload', function (): void {
        $this->postJson('/graphql', ['query' => 'mutation { login(email: "ada@example.com", password: "hunter22hunter22") }'])->assertOk();

        $capture = latestCapture();
        $request = $capture->section('request');
        $masked = 'mutation { login(email: "ada@example.com", password: "hunt****") }';
        $pretty = "mutation {\n  login(email: \"ada@example.com\", password: \"hunt****\")\n}";

        expect($capture->rows('graphql')[0]['query'])->toBe($masked)
            ->and($request['input'])->toBe(['query' => $masked])
            ->and($request['input_formats'])->toBe(['query' => ['format' => 'graphql', 'text' => $pretty]])
            ->and($request['response_capture']['cards'][0]['items']['query'])->toBe($pretty);
    });

    it('formats each query of a batch in the payload', function (): void {
        $this->postJson('/graphql', [['query' => '{ hello }'], ['query' => '{ ping }'], 'not an operation']);

        expect(array_keys(latestCapture()->section('request')['input_formats']))->toBe(['0.query', '1.query']);
    });

    it('leaves the payload format out where input masking hides the query', function (): void {
        config(['monocle.mask.inputs' => '/query|pass/i']);

        $this->postJson('/graphql', ['query' => '{ hello }'])->assertOk();

        expect(latestCapture()->section('request'))->not->toHaveKey('input_formats')
            ->and(latestCapture()->section('request')['input']['query'])->not->toBe('{ hello }');
    });

    it('shows one card per operation of a batch, errors counted', function (): void {
        $this->postJson('/graphql', [
            ['query' => '{ hello }'],
            ['query' => 'query Broken { nope nada }'],
        ])->assertOk();

        [$ok, $broken] = graphqlCards();

        expect($ok)->toBe(['title' => 'query', 'subtitle' => null, 'items' => ['query' => "{\n  hello\n}", 'data' => ['hello' => 'Hello world']], 'formats' => ['query' => 'graphql']])
            ->and($broken['title'])->toBe('query Broken')
            ->and($broken['subtitle'])->toBe('2 errors')
            ->and($broken['items']['errors'][0]['message'])->toContain('Cannot query field "nope"')
            ->and($broken['items']['errors'][0]['location'])->toBe('1:16');
    });

    it('shows each error as one flat row, without the debug trace', function (): void {
        $request = Request::create('/graphql', 'POST', ['query' => '{ boom }']);
        $response = new JsonResponse(['data' => ['boom' => null], 'errors' => [[
            'message' => 'Internal server error',
            'locations' => [['line' => 1, 'column' => 3]],
            'path' => ['boom', 0, 'name'],
            'extensions' => ['debugMessage' => 'resolver exploded', 'file' => 'app/Resolvers.php', 'line' => 24, 'trace' => [['file' => 'x']], 'category' => 'internal'],
        ], ['message' => 'Bare', 'extensions' => ['file' => 'app/Other.php']]]]);

        expect(app(GraphQLCapture::class)->format($request, $response)->response[0]['items']['errors'])->toBe([
            [
                'message' => 'Internal server error',
                'path' => 'boom.0.name',
                'location' => '1:3',
                'debug' => 'resolver exploded',
                'file' => 'app/Resolvers.php:24',
                'extensions' => '{"category":"internal"}',
            ],
            ['message' => 'Bare', 'file' => 'app/Other.php'],
        ]);
    });

    it('shows a rebing operation on a schema route, and a query that does not parse', function (): void {
        $this->postJson('/rebing/own', ['query' => '{ hello }', 'operationName' => null])->assertOk();

        expect(graphqlCards()[0]['items']['data'])->toBe(['hello' => 'Hello world']);

        $this->postJson('/graphql', ['query' => '{ hello(', 'operationName' => 'Half'])->assertOk();

        expect(graphqlCards()[0])->toMatchArray(['title' => 'GraphQL Half', 'subtitle' => '1 error']);
    });

    it('reads the operations of a multipart upload', function (): void {
        $request = Request::create('/graphql', 'POST', ['operations' => json_encode(['query' => '{ hello }'])]);

        expect(app(GraphQLCapture::class)->format($request, new JsonResponse(['data' => ['hello' => 'Hello world']]))->response[0]['items'])
            ->toBe(['query' => "{\n  hello\n}", 'data' => ['hello' => 'Hello world']]);
    });

    it('leaves responses alone that are not GraphQL results', function (): void {
        $capture = app(GraphQLCapture::class);
        $request = Request::create('/graphql', 'POST');

        expect($capture->format($request, new Response('<html></html>'))->response)->toBeNull()
            ->and($capture->format($request, new JsonResponse(['message' => 'Server Error']))->response)->toBeNull();
    });
});
