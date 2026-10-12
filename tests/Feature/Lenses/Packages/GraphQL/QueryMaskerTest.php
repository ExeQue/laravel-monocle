<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\GraphQL;

use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\GraphQL\QueryMasker;

covers(QueryMasker::class);

describe(QueryMasker::class, function (): void {
    $mask = fn (string $query): string => QueryMasker::mask($query, app(Eyepiece::class)->masker());

    it('masks values written into arguments and input objects by their name', function () use ($mask): void {
        expect($mask('mutation { login(email: "ada@example.com", password: "hunter22hunter22") { token } }'))
            ->toBe('mutation { login(email: "ada@example.com", password: "hunt****") { token } }')
            ->and($mask('mutation { save(input: { name: "Ada", secret: """block secret""", pin_token: 123456, kind: TOKEN_KIND }) }'))
            ->toBe('mutation { save(input: { name: "Ada", secret: "blo****", pin_token: "1****", kind: TOKEN_KIND }) }')
            ->and($mask('{ users(tokens: ["aaaaaaaa", "bbbbbbbb", [nested]], first: 2) { id } }'))
            ->toBe('{ users(tokens: ["aa****", "bb****", ["n****"]], first: 2) { id } }');
    });

    it('masks the default value of a variable whose name is masked', function () use ($mask): void {
        expect($mask('query ($password: String = "hunter22hunter22", $name: String = "Ada") { me(password: $password) }'))
            ->toBe('query ($password: String = "hunt****", $name: String = "Ada") { me(password: $password) }');
    });

    it('leaves aliases, field names and variables alone', function () use ($mask): void {
        $query = '{ password: secretField token { id } }';

        expect($mask($query))->toBe($query);
    });

    it('keeps the layout and characters around a masked value', function () use ($mask): void {
        expect($mask("# æøå 漢字\nmutation {\n  login(password: \"hunter22hunter22\")\n}"))
            ->toBe("# æøå 漢字\nmutation {\n  login(password: \"hunt****\")\n}");
    });

    it('masks what it can read before a document breaks', function () use ($mask): void {
        expect($mask('{ a(password: "hunter22hunter22") b(token: "unterminated'))
            ->toBe('{ a(password: "hunt****") b(token: "unterminated');
    });
});
