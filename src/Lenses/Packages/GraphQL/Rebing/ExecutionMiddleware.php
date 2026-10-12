<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\GraphQL\Rebing;

use Closure;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLLens;
use ExeQue\Monocle\Lenses\Packages\GraphQL\RootFields;
use GraphQL\Error\Error;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use Rebing\GraphQL\Support\ExecutionMiddleware\AbstractExecutionMiddleware;
use Rebing\GraphQL\Support\Field;
use Rebing\GraphQL\Support\OperationParams;
use ReflectionFunction;
use Throwable;

/**
 * Times a rebing/graphql-laravel operation through the rest of its
 * execution middleware, parsing and validation included. GraphQLLens puts
 * it first in every list.
 *
 * @internal
 */
final class ExecutionMiddleware extends AbstractExecutionMiddleware
{
    public function __construct(
        private readonly GraphQLLens $lens,
        private readonly Eyepiece $eyepiece,
    ) {
    }

    public function handle(string $schemaName, Schema $schema, OperationParams $params, $rootValue, $contextValue, Closure $next): ExecutionResult
    {
        $pending = $this->eyepiece->wrap(fn (): ?array => $this->lens->start(
            'rebing',
            $schemaName,
            $this->eyepiece->outside(fn (): ?DocumentNode => $this->parsed($params)) ?? $params->query,
            $params->operation,
            $params->variables,
        ))();

        if ($pending === null) {
            return $next($schemaName, $schema, $params, $rootValue, $contextValue);
        }

        try {
            $result = $next($schemaName, $schema, $params, $rootValue, $contextValue);
        } catch (Throwable $e) {
            $this->eyepiece->wrap(fn () => $this->lens->finish($pending, [new Error($e->getMessage(), previous: $e)], $this->fill($schema, $params)))();

            throw $e;
        }

        $this->eyepiece->wrap(fn () => $this->lens->finish($pending, $result->errors, $this->fill($schema, $params)))();

        return $result;
    }

    /**
     * What an automatic persisted query only has once it has been looked up.
     *
     * @return array<string, mixed>
     */
    private function fill(Schema $schema, OperationParams $params): array
    {
        $document = $this->parsed($params);
        $definition = $document === null ? null : AST::getOperationAST($document, $params->operation);

        return [
            'type' => $definition?->operation,
            'operation' => $definition?->name?->value,
            'query' => $params->query,
            'fields' => $definition === null ? null : ($this->fields($schema, $document, $definition) ?: null),
        ];
    }

    /**
     * The rebing field class whose resolve() each root field runs: rebing
     * builds the resolver as a closure inside the field object.
     *
     * @return list<array{field: string, resolver: string, definition: null}>
     */
    private function fields(Schema $schema, DocumentNode $document, OperationDefinitionNode $operation): array
    {
        $type = $schema->getOperationType($operation->operation);

        return collect(RootFields::of($document, $operation->selectionSet))
            ->mapWithKeys(function (string $name) use ($type): array {
                $resolve = $type?->findField($name)?->resolveFn;

                return [$name => $resolve instanceof Closure ? new ReflectionFunction($resolve)->getClosureThis() : null];
            })
            ->whereInstanceOf(Field::class)
            // The field class is its own definition, so there is no schema file to point to.
            ->map(fn (Field $field, string $name): array => ['field' => $name, 'resolver' => $field::class.'@resolve', 'definition' => null])
            ->values()
            ->all();
    }

    /**
     * rebing parses the query once and keeps it; null while there is none
     * to parse or it does not parse.
     */
    private function parsed(OperationParams $params): ?DocumentNode
    {
        if (! is_string($params->query)) {
            return null;
        }

        try {
            return $params->getParsedQuery();
        } catch (Throwable) {
            return null;
        }
    }
}
