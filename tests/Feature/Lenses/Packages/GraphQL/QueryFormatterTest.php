<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\GraphQL;

use ExeQue\Monocle\Lenses\Packages\GraphQL\QueryFormatter;

covers(QueryFormatter::class);

describe(QueryFormatter::class, function (): void {
    it('prints a document one field per line', function (): void {
        expect(QueryFormatter::pretty('query Greet($name:String){hello(name:$name)}'))
            ->toBe("query Greet(\$name: String) {\n  hello(name: \$name)\n}");
    });

    it('keeps a document that does not parse as it was sent', function (): void {
        expect(QueryFormatter::pretty('{ hello('))->toBe('{ hello(');
    });
});
