<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\GraphQL\Rebing;

use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLHook;
use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLLens;
use Illuminate\Contracts\Foundation\Application;

/**
 * rebing/graphql-laravel operations. rebing runs each operation through its
 * execution middleware, so Monocle's goes first: it sees parse and
 * validation errors too.
 */
final readonly class RebingHook implements GraphQLHook, RequiresPackages
{
    public function __construct(
        private Application $app,
    ) {
    }

    public static function packages(): array
    {
        return ['rebing/graphql-laravel'];
    }

    public function hook(GraphQLLens $lens): void
    {
        // After every provider has booted, so rebing's own defaults are in the config.
        $this->app->booted(function (Application $app): void {
            $config = $app->make('config');

            $prepend = fn (array $middleware): array => [
                ExecutionMiddleware::class,
                ...array_filter($middleware, fn (mixed $class): bool => $class !== ExecutionMiddleware::class),
            ];

            $config->set('graphql.execution_middleware', $prepend($config->get('graphql.execution_middleware') ?? []));

            // A schema with a list of its own uses it instead of the global one.
            foreach ($config->get('graphql.schemas') ?? [] as $name => $schema) {
                if (is_array($schema['execution_middleware'] ?? null)) {
                    $config->set("graphql.schemas.{$name}.execution_middleware", $prepend($schema['execution_middleware']));
                }
            }
        });
    }
}
