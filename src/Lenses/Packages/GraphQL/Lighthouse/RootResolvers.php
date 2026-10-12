<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\GraphQL\Lighthouse;

use ExeQue\Monocle\Lenses\Packages\GraphQL\RootFields;
use GraphQL\Language\AST\DirectiveNode;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Schema;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Schema\AST\ASTHelper;
use Nuwave\Lighthouse\Schema\DirectiveLocator;
use Nuwave\Lighthouse\Support\Contracts\FieldResolver;
use Nuwave\Lighthouse\Support\Utils;

/**
 * Where each root field of a Lighthouse operation is resolved and defined:
 * the resolver as Class@method (the class named by @field, the directive
 * that resolves it such as @all, or the class Lighthouse finds by the
 * field's name) and the schema file and line of its definition.
 *
 * @internal
 */
final readonly class RootResolvers
{
    private const array NAMESPACES = ['query' => 'queries', 'mutation' => 'mutations', 'subscription' => 'subscriptions'];

    public function __construct(
        private DirectiveLocator $directives,
        private SchemaFiles $files,
    ) {
    }

    /**
     * @return list<array{field: string, resolver: string, definition: string|null}>
     */
    public function of(Schema $schema, DocumentNode $document, OperationDefinitionNode $operation): array
    {
        $type = $schema->getOperationType($operation->operation);

        if ($type === null) {
            return [];
        }

        $namespaces = (array) config('lighthouse.namespaces.'.self::NAMESPACES[$operation->operation], []);
        return collect(RootFields::of($document, $operation->selectionSet))
            ->mapWithKeys(fn (string $name): array => [$name => $type->findField($name)])
            ->filter()
            ->map(fn (FieldDefinition $field, string $name): array => [
                'field' => $name,
                'resolver' => $this->resolver($name, $field->astNode?->directives ?? [], $namespaces),
                'definition' => $this->files->find($type->name, $name),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  iterable<DirectiveNode>  $directives
     * @param  list<string>  $namespaces
     */
    private function resolver(string $field, iterable $directives, array $namespaces): string
    {
        foreach ($directives as $directive) {
            $class = $this->directive($directive->name->value);

            if ($class === null) {
                continue;
            }

            if ($directive->name->value !== 'field') {
                return $class.'@resolveField';
            }

            [$candidate, $method] = explode('@', (string) ASTHelper::directiveArgValue($directive, 'resolver'), 2) + [1 => '__invoke'];

            return (Utils::namespaceClassname($candidate, $namespaces, fn (string $class): bool => method_exists($class, $method)) ?? $candidate).'@'.$method;
        }

        // Without a resolver directive Lighthouse looks for a class named after the field; it refuses a root field without one.
        return Utils::namespaceClassname(Str::studly($field), $namespaces, fn (string $class): bool => method_exists($class, '__invoke')).'@__invoke';
    }

    /**
     * The directive class when it resolves fields. Lighthouse refuses a
     * schema that uses a directive it has no class for.
     *
     * @return class-string<FieldResolver>|null
     */
    private function directive(string $name): ?string
    {
        $class = $this->directives->resolve($name);

        return is_a($class, FieldResolver::class, true) ? $class : null;
    }
}
