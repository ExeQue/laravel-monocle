<?php

declare(strict_types=1);

use ExeQue\Monocle\Reader\RowQuery;

covers(RowQuery::class);

describe(RowQuery::class, function (): void {
    $rows = fn (): array => array_map(
        fn (int $n): array => ['n' => $n, 'duration' => $n * 1.5, 'sql' => "select {$n}", 'origin' => ['file' => "app/F{$n}.php", 'line' => $n, 'frames' => [['file' => 'x', 'line' => 1, 'call' => null]]]],
        range(1, 120),
    );

    describe('pagination', function () use ($rows): void {
        it('pages with total and next_offset', function () use ($rows): void {
            $first = RowQuery::fromArray(['limit' => 50])->apply($rows());

            expect($first['total'])->toBe(120)
                ->and($first['returned'])->toBe(50)
                ->and($first['next_offset'])->toBe(50);

            $last = RowQuery::fromArray(['limit' => 50, 'offset' => 100])->apply($rows());

            expect($last['returned'])->toBe(20)
                ->and($last['next_offset'])->toBeNull();
        });

        it('clamps the limit to the maximum', function (): void {
            expect(RowQuery::fromArray(['limit' => 10000])->limit)->toBe(RowQuery::MAX_LIMIT)
                ->and(RowQuery::fromArray(['limit' => 0])->limit)->toBe(1);
        });
    });

    describe('filtering and sorting', function () use ($rows): void {
        it('applies where clauses with numeric comparison', function () use ($rows): void {
            $result = RowQuery::fromArray(['where' => [['field' => 'duration', 'op' => '>', 'value' => '150']]])->apply($rows());

            expect($result['total'])->toBe(20)
                ->and($result['filtered_from'])->toBe(120);
        });

        it('supports contains and in', function () use ($rows): void {
            expect(RowQuery::fromArray(['where' => [['field' => 'sql', 'op' => 'contains', 'value' => 'SELECT 1']]])->apply($rows())['total'])->toBe(32)
                ->and(RowQuery::fromArray(['where' => [['field' => 'n', 'op' => 'in', 'value' => [1, 2, 3]]]])->apply($rows())['total'])->toBe(3);
        });

        it('sorts descending by a dotted field', function () use ($rows): void {
            $result = RowQuery::fromArray(['sort' => 'origin.line', 'dir' => 'desc', 'limit' => 1])->apply($rows());

            expect($result['rows'][0]['n'])->toBe(120);
        });
    });

    describe('projection', function () use ($rows): void {
        it('returns only the requested fields', function () use ($rows): void {
            $result = RowQuery::fromArray(['fields' => ['n', 'origin.file'], 'limit' => 1])->apply($rows());

            expect($result['rows'][0])->toBe(['n' => 1, 'origin' => ['file' => 'app/F1.php']]);
        });

        it('compacts origin to file:line unless verbose', function () use ($rows): void {
            $compact = RowQuery::fromArray(['limit' => 1])->apply($rows());
            $verbose = RowQuery::fromArray(['limit' => 1, 'verbose' => true])->apply($rows());

            $via = RowQuery::fromArray([])->apply([['origin' => ['file' => 'a.php', 'line' => 2, 'via' => ['call' => 'Lib->go']]]]);

            expect($compact['rows'][0]['origin'])->toBe('app/F1.php:1')
                ->and($via['rows'][0]['origin'])->toBe('a.php:2 via Lib->go')
                ->and($verbose['rows'][0]['origin']['frames'])->toHaveCount(1);
        });
    });
});
