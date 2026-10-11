<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Concerns;

use ExeQue\Monocle\Console\Concerns\RendersForHumans;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\BufferedOutput;

covers(RendersForHumans::class);

describe(RendersForHumans::class, function (): void {
    beforeEach(function (): void {
        $this->output = new BufferedOutput();
        Prompt::setOutput($this->output);

        $this->helper = new class () {
            use RendersForHumans {
                amount as public;
                readable as public;
                shortOrigin as public;
                failed as public;
                columns as public;
                rowsTable as public;
                dotTable as public;
                paging as public;
                flags as public;
                cell as public;
                ago as public;
            }
        };
    });

    it('formats amounts by the name they are stored under', function (): void {
        expect($this->helper->amount('duration', 12.34))->toBe('12.3 ms')
            ->and($this->helper->amount('query_ms', 1500))->toBe('1.50 s')
            ->and($this->helper->amount('response_bytes', 2048))->toBe('2.0 KB')
            ->and($this->helper->amount('memory_peak_mb', 6))->toBe('6 MB')
            ->and($this->helper->amount('queries', 3))->toBe('3')
            ->and($this->helper->amount('queries', null))->toBe('')
            ->and($this->helper->amount('status', 'ok'))->toBe('ok')
            ->and($this->helper->readable(['time' => 1.0, 'name' => 'x']))->toBe(['time' => '1.0 ms', 'name' => 'x']);
    });

    it('escapes style tags and flattens cells to one short line', function (): void {
        expect($this->helper->cell("<fg=red>a\n  b</>"))->toBe(OutputFormatter::escape('<fg=red>a b</>'))
            ->and($this->helper->cell(str_repeat('x', 100), 10))->toBe(str_repeat('x', 10).'...')
            ->and($this->helper->ago(null))->toBe('');
    });

    it('shortens origins to the project and the class name', function (): void {
        expect($this->helper->shortOrigin(base_path('app/Orders.php').':12 via App\\Models\\Order->save'))->toBe('app/Orders.php:12 via Order->save')
            ->and($this->helper->shortOrigin(null))->toBe('');
    });

    it('knows failed captures', function (array $capture, bool $failed): void {
        expect($this->helper->failed($capture))->toBe($failed);
    })->with([
        'failed job' => [['status' => 'failed'], true],
        '5xx' => [['status' => '503'], true],
        '2xx' => [['status' => '200'], false],
        'processed' => [['status' => 'processed'], false],
    ]);

    it('picks plain, filled columns and keeps the duration', function (): void {
        $rows = [
            ['id' => 1, 'sql' => 'a', 'tags' => ['x', 'y'], 'meta' => ['k' => 'v'], 'nested' => [['a' => 1]], 'empty' => null, 'a' => 1, 'b' => 2, 'duration' => 3],
        ];

        expect($this->helper->columns($rows, 3))->toBe([['sql', 'tags', 'duration'], ['a', 'b']]);
    });

    it('renders tables, notes and flags', function (): void {
        $this->helper->rowsTable([]);
        $this->helper->dotTable([]);
        $this->helper->rowsTable([['n' => 1, 'name' => 'first']], ['name' => 'Who']);
        $this->helper->dotTable(['request' => ['method' => 'GET']]);
        $this->helper->paging(['offset' => 0, 'returned' => 2, 'total' => 5, 'next_offset' => 2], 'php artisan monocle:rows queries');
        $this->helper->paging(['offset' => 0, 'returned' => 2, 'total' => 2, 'next_offset' => null]);
        $this->helper->flags(['slow'], ['broken']);

        expect($this->output->fetch())->toContain('No rows.')
            ->toContain('Empty.')
            ->toContain('Who')
            ->toContain('first')
            ->toContain('request.method')
            ->toContain('Showing 1-2 of 5. Next page: php artisan monocle:rows queries --offset=2')
            ->toContain('slow')
            ->toContain('broken');
    });

    it('prints no paging line for an empty page', function (): void {
        $this->helper->paging(['offset' => 0, 'returned' => 0, 'total' => 0, 'next_offset' => null]);

        expect($this->output->fetch())->toBe('');
    });
});
