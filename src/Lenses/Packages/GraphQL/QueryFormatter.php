<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\GraphQL;

use GraphQL\Error\SyntaxError;
use GraphQL\Language\Parser;
use GraphQL\Language\Printer;

/**
 * GraphQL documents for the dashboard, printed in webonyx's canonical
 * layout. The dashboard colours them (resources/js/render/graphql.js).
 *
 * @internal
 */
final class QueryFormatter
{
    /**
     * The document printed with one field per line; as it was sent when it
     * does not parse.
     */
    public static function pretty(string $query): string
    {
        try {
            return rtrim(Printer::doPrint(Parser::parse($query, ['noLocation' => true])));
        } catch (SyntaxError) {
            return $query;
        }
    }
}
