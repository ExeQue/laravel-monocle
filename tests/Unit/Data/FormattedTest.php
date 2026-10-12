<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use ExeQue\Monocle\Data\Card;
use ExeQue\Monocle\Data\Formatted;

covers(Formatted::class);

describe(Formatted::class, function (): void {
    it('leaves both sides alone until told otherwise', function (): void {
        expect(Formatted::make()->request)->toBeNull()
            ->and(Formatted::make()->response)->toBeNull()
            ->and(Formatted::make()->payload(['a' => 1], ['a' => 1]))->toBe([['a' => 1], []]);
    });

    it('collects cards fluently without changing the instance it came from', function (): void {
        $empty = Formatted::make();
        $formatted = $empty->card(Card::titled('Order 12')->item('status', 'paid'))->card(Card::titled('Lines')->subtitle('2 lines')->item('count', 2, 'graphql'));

        expect($empty->response)->toBeNull()
            ->and($formatted->response)->toBe([
                ['title' => 'Order 12', 'subtitle' => null, 'items' => ['status' => 'paid']],
                ['title' => 'Lines', 'subtitle' => '2 lines', 'items' => ['count' => 2], 'formats' => ['count' => 'graphql']],
            ]);
    });

    it('formats payload fields and records replaced values', function (): void {
        $formatted = Formatted::make()
            ->field('query', 'graphql', "{\n  a\n}")
            ->replace('query', '{ a }')
            ->replace('ops.0.secret', 'xx****')
            ->field('password', 'graphql');
        $sent = ['query' => '{a}', 'ops' => [['secret' => 'xxxxxxxx']], 'password' => 'hunter22'];
        $masked = ['query' => '{a}', 'ops' => [['secret' => 'xxxxxxxx']], 'password' => 'hunt****'];

        expect($formatted->request)->toBe([
            'query' => ['format' => 'graphql', 'text' => "{\n  a\n}", 'value' => '{ a }'],
            'ops.0.secret' => ['value' => 'xx****'],
            'password' => ['format' => 'graphql'],
        ])
            ->and($formatted->payload($sent, $masked))->toBe([
                ['query' => '{ a }', 'ops' => [['secret' => 'xx****']], 'password' => 'hunt****'],
                ['query' => ['format' => 'graphql', 'text' => "{\n  a\n}"]],
            ]);
    });
});
