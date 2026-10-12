<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\GraphQL;

use Illuminate\View\View;

/**
 * A page that sends a GraphQL request to Lighthouse (/graphql) or rebing
 * (/rebing). The GraphQL request is a capture of its own.
 */
final class OperationController
{
    private const array DEMOS = [
        'lighthouse' => ['Lighthouse operation', 'A named query with a variable, served by Lighthouse.', '/graphql', ['query' => 'query Greet($name: String) { hello(name: $name) }', 'variables' => ['name' => 'Ada']]],
        'lighthouse-login' => ['Masked variables', 'A mutation whose password variable is masked.', '/graphql', ['query' => 'mutation Login($email: String!, $password: String!) { login(email: $email, password: $password) }', 'variables' => ['email' => 'ada@example.com', 'password' => 'hunter22hunter22']]],
        'lighthouse-batch' => ['Batch', 'Two operations in one request: one ok, one that fails validation.', '/graphql', [['query' => 'query Greet { hello }'], ['query' => 'query Broken { nope }']]],
        'rebing' => ['rebing operation', 'A named query with a variable, served by rebing/graphql-laravel.', '/rebing', ['query' => 'query Greet($name: String) { hello(name: $name) }', 'variables' => ['name' => 'Grace']]],
        'resolver-exception' => ['Resolver exception', 'A field whose resolver throws; the response says only "Internal server error".', '/graphql', ['query' => 'query Explode { boom }']],
        'validation-error' => ['Validation error', 'A query for fields that do not exist, answered with 200.', '/rebing', ['query' => 'query Broken { nope nada }']],
    ];

    public function __invoke(string $demo): View
    {
        [$title, $description, $url, $body] = self::DEMOS[$demo] ?? abort(404);

        return view('fetch', [
            'title' => $title,
            'description' => $description.' The GraphQL request is a capture of its own.',
            'method' => 'POST',
            'url' => $url,
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            'body' => $body,
        ]);
    }
}
