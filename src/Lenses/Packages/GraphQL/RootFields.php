<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\GraphQL;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\SelectionNode;
use GraphQL\Language\AST\SelectionSetNode;

/**
 * The fields an operation selects on its root type, through fragments too.
 * Introspection fields (__typename, __schema, __type) are left out.
 *
 * @internal
 */
final class RootFields
{
    /**
     * @return list<string>
     */
    public static function of(DocumentNode $document, SelectionSetNode $selections): array
    {
        return collect($selections->selections)
            ->flatMap(fn (SelectionNode $selection): array => match (true) {
                $selection instanceof FieldNode => [$selection->name->value],
                $selection instanceof InlineFragmentNode => self::of($document, $selection->selectionSet),
                // A selection is a field, an inline fragment or a fragment spread.
                default => self::fragment($document, $selection->name->value),
            })
            ->reject(fn (string $field): bool => str_starts_with($field, '__'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private static function fragment(DocumentNode $document, string $name): array
    {
        foreach ($document->definitions as $definition) {
            if ($definition instanceof FragmentDefinitionNode && $definition->name->value === $name) {
                return self::of($document, $definition->selectionSet);
            }
        }

        return [];
    }
}
