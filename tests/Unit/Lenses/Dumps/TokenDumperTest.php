<?php

declare(strict_types=1);

namespace Tests\Unit\Lenses\Dumps;

use ExeQue\Monocle\Lenses\Dumps\TokenDumper;
use stdClass;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

covers(TokenDumper::class);

describe(TokenDumper::class, function (): void {
    $plain = function (mixed $value): string {
        $dumper = new CliDumper();
        $dumper->setColors(false);

        return (string) $dumper->dump(new VarCloner()->cloneVar($value), true);
    };

    it('prints the same text as the plain dumper and splits it into styled pieces', function () use ($plain): void {
        $object = new stdClass();
        $object->name = 'Ada "the" => one';
        $value = ['person' => $object, 3 => true];

        [$text, $tokens] = new TokenDumper()->tokenize(new VarCloner()->cloneVar($value));

        expect($text)->toBe($plain($value))
            ->and(implode('', array_column($tokens, 1)))->toBe($text)
            ->and($tokens)->toContain(['key', 'person'], ['public', 'name'], ['str', 'Ada "the" => one'], ['const', 'true'], ['note', 'array:2']);
    });

    it('marks a value VarDumper styles in parts once', function () use ($plain): void {
        // A closure's file is shortened with an ellipsis, styled as a prefix and a value inside one style.
        $closure = fn (): int => 1;

        [$text, $tokens] = new TokenDumper()->tokenize(new VarCloner()->cloneVar($closure));

        expect($text)->toBe($plain($closure))
            ->and($text)->not->toMatch('/[\x1D\x1E\x1F]/');
    });
});
