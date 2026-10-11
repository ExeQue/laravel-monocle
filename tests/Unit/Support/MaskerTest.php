<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use ExeQue\Monocle\Support\Masker;
use ExeQue\Monocle\Support\Masks\AuthorizationMask;
use ExeQue\Monocle\Support\Masks\FullMask;
use ExeQue\Monocle\Support\Masks\PartialMask;

covers(Masker::class, PartialMask::class, FullMask::class, AuthorizationMask::class);

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

describe(AuthorizationMask::class, function (): void {
    it('keeps a known scheme and masks only the credentials', function (): void {
        $mask = new AuthorizationMask(['Basic', 'Bearer']);

        expect($mask('Bearer abcdefghijklmnopqrstuvwxyz', 'authorization'))->toBe('Bearer abcd****')
            ->and($mask('basic dXNlcjpwYXNzd29yZA==', 'authorization'))->toBe('basic dXNl****');
    });

    it('masks the whole value when the scheme is unknown or missing', function (): void {
        $mask = new AuthorizationMask(['Basic', 'Bearer']);

        expect($mask('Custom abcdefghijklmnopqrstuvwxyz', 'authorization'))->toBe('Cust****')
            ->and($mask('abcdefghijklmnop', 'authorization'))->toBe('abcd****')
            ->and($mask(['x'], 'authorization'))->toBe('****');
    });

    it('takes its own schemes and visible length', function (): void {
        $mask = new AuthorizationMask(['Custom'], 2);

        expect($mask('Custom abcdefghijklmnop', 'authorization'))->toBe('Custom ab****')
            ->and($mask('Bearer abcdefghijklmnop', 'authorization'))->toBe('Be****');
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

    it('uses the mask configured per header and per cookie, with wildcards', function (): void {
        $masker = new Masker(
            ['authorization' => new FullMask(), 'x-api-*' => new PartialMask(), 'x-plain'],
            '/pass/i',
            fn (): string => 'default',
            ['remember_*' => new FullMask(), 'XSRF-TOKEN'],
        );

        expect($masker->headers(['Authorization' => 'Bearer secret', 'X-Api-Key' => 'abcdefghijklmnop', 'X-Plain' => 'v', 'Accept' => 'a']))
            ->toBe(['Authorization' => '********', 'X-Api-Key' => 'abcd****', 'X-Plain' => 'default', 'Accept' => 'a'])
            ->and($masker->cookies(['remember_web_59ba' => 'secret', 'XSRF-TOKEN' => 'x', 'password_hint' => 'p', 'theme' => 'dark']))
            ->toBe(['remember_web_59ba' => '********', 'XSRF-TOKEN' => 'default', 'password_hint' => 'default', 'theme' => 'dark']);
    });

    it('masks capture card items and json or form bodies like input', function (): void {
        $masker = new Masker([], '/token|pass/i', new FullMask());

        expect($masker->cards([['title' => 'JSON', 'items' => ['access_token' => 'abc', 'user' => ['name' => 'ada', 'password' => 'x']]]]))
            ->toBe([['title' => 'JSON', 'items' => ['access_token' => '********', 'user' => ['name' => 'ada', 'password' => '********']]]])
            ->and($masker->body('{"access_token":"abc","expires_in":3600}'))->toBe('{"access_token":"********","expires_in":3600}')
            ->and($masker->body('grant_type=password&password=hunter2', 'application/x-www-form-urlencoded'))->toBe('grant_type=password&password=********')
            ->and($masker->body('<p>token=abc</p>', 'text/html'))->toBe('<p>token=abc</p>');
    });
});
