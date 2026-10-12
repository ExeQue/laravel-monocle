<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use ExeQue\Monocle\Data\Card;

covers(Card::class);

describe(Card::class, function (): void {
    it('builds a card fluently without changing the card it came from', function (): void {
        $titled = Card::titled('mutation Login');
        $card = $titled
            ->subtitle('1 error')
            ->item('query', 'mutation { login }', 'graphql')
            ->items(['data' => null, 'errors' => [['message' => 'nope']]]);

        expect($titled->toArray())->toBe(['title' => 'mutation Login', 'subtitle' => null, 'items' => []])
            ->and($card->toArray())->toBe([
                'title' => 'mutation Login',
                'subtitle' => '1 error',
                'items' => ['query' => 'mutation { login }', 'data' => null, 'errors' => [['message' => 'nope']]],
                'formats' => ['query' => 'graphql'],
            ]);
    });

    it('drops the format of an item set again without one', function (): void {
        expect(Card::titled('Q')->item('query', '{ a }', 'graphql')->item('query', '{ b }')->toArray())
            ->toBe(['title' => 'Q', 'subtitle' => null, 'items' => ['query' => '{ b }']]);
    });

    it('takes collections for items and item values', function (): void {
        expect(Card::titled('Q')->items(collect(['a' => 1]))->item('list', collect([1, 2]))->toArray()['items'])
            ->toBe(['a' => 1, 'list' => [1, 2]]);
    });
});
