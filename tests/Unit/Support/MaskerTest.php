<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use ExeQue\Monocle\Support\Masker;
use ExeQue\Monocle\Support\Masks\FullMask;
use ExeQue\Monocle\Support\Masks\PartialMask;

covers(Masker::class, PartialMask::class, FullMask::class);

describe(PartialMask::class, function (): void {
    it('keeps at most four characters and never more than a quarter', function (): void {
        $mask = new PartialMask();

        expect($mask('abcdefghijklmnopqrstuvwxyz', 'token'))->toBe('abcd****')
            ->and($mask('abcdefgh', 'token'))->toBe('ab****')
            ->and($mask('abc', 'token'))->toBe('****')
            ->and($mask('', 'token'))->toBe('')
            ->and($mask(['nested'], 'token'))->toBe('****');
    });
});

describe(Masker::class, function (): void {
    it('masks configured headers case-insensitively and flattens single values', function (): void {
        $masker = new Masker(['authorization'], null, new FullMask());

        expect($masker->headers(['Authorization' => ['Bearer secret'], 'Accept' => ['text/html']]))
            ->toBe(['Authorization' => '********', 'Accept' => 'text/html']);
    });

    it('masks input keys matching the pattern recursively', function (): void {
        $masker = new Masker([], '/pass|token/i', new PartialMask());

        expect($masker->inputs(['email' => 'a@b.c', 'password' => 'hunter22hunter22', 'nested' => ['api_token' => 'xyz']]))
            ->toBe(['email' => 'a@b.c', 'password' => 'hunt****', 'nested' => ['api_token' => '****']]);
    });

    it('accepts any callable as mask', function (): void {
        $masker = new Masker(['x-secret'], null, fn (mixed $value, string $key): string => strtoupper($key));

        expect($masker->headers(['X-Secret' => 'v']))->toBe(['X-Secret' => 'X-SECRET']);
    });
});
