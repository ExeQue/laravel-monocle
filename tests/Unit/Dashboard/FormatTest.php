<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use ExeQue\Monocle\Dashboard\Format;
use stdClass;

covers(Format::class);

describe(Format::class, function (): void {
    it('writes durations in ms, seconds, or as below a tenth', function (): void {
        expect(Format::ms(null))->toBe('0.0 ms')
            ->and(Format::ms(0.01))->toBe('<0.1 ms')
            ->and(Format::ms(12.345))->toBe('12.3 ms')
            ->and(Format::ms(1500))->toBe('1.50 s');
    });

    it('writes sizes in bytes, KB and MB', function (): void {
        expect(Format::bytes(512))->toBe('512 B')
            ->and(Format::bytes(2048))->toBe('2.0 KB')
            ->and(Format::bytes(3 * 1048576))->toBe('3.0 MB');
    });

    it('writes any value as one line of text', function (): void {
        expect(Format::value(null))->toBe('')
            ->and(Format::value(true))->toBe('true')
            ->and(Format::value(false))->toBe('false')
            ->and(Format::value(42))->toBe('42')
            ->and(Format::value(['a', 1]))->toBe('a, 1')
            ->and(Format::value([]))->toBe('[]')
            ->and(Format::value(['k' => 'v']))->toBe('{"k":"v"}')
            ->and(Format::value([['nested']]))->toBe('[["nested"]]')
            ->and(Format::value(new stdClass()))->toBe('{}');
    });

    it('pretty prints arrays and JSON text and keeps other text as it is', function (): void {
        expect(Format::pretty(['city' => 'Aarhus']))->toBe("{\n    \"city\": \"Aarhus\"\n}")
            ->and(Format::pretty('{"ok":true}'))->toBe("{\n    \"ok\": true\n}")
            ->and(Format::pretty('sunny'))->toBe('sunny')
            ->and(Format::pretty('42'))->toBe('42')
            ->and(Format::pretty(null))->toBe('');
    });

    it('colours a dump by the pieces VarDumper styled, with the classes it names as names', function (): void {
        $row = ['value' => 'ignored', 'tokens' => [
            ['note', 'Tests\\Fixtures\\Models\\Writer'], ['', ' {'], ['ref', '#12'], ['', "\n  #"], ['protected', 'connection'], ['', ': "'], ['str', 'sqlite'],
            ['', '" '], ['num', '7'], ['', ' '], ['const', 'null'], ['', ' '], ['note', 'array:2'], ['', ' '], ['key', '<b>'],
        ]];

        expect(Format::dump($row))->toBe(
            '<span data-monocle-name data-full>Tests\\Fixtures\\Models\\Writer</span> {<span class="text-muted">#12</span>'
            ."\n  #<span class=\"text-muted\">connection</span>: &quot;<span class=\"text-ok\">sqlite</span>"
            .'&quot; <span class="text-brass">7</span> <span class="text-brass">null</span> <span class="text-muted">array:2</span> &lt;b&gt;'
        );
    });

    it('shows a dump without tokens as plain text', function (): void {
        expect(Format::dump(['value' => '"<b>"']))->toBe('&quot;&lt;b&gt;&quot;')
            ->and(Format::dump([]))->toBe('');
    });

    it('flattens nested data into dot notated pairs', function (): void {
        expect(Format::dot('loose'))->toBe([])
            ->and(Format::dot([]))->toBe([])
            ->and(Format::dot(['user' => ['name' => 'Ada', 'tags' => []], 'ids' => [1, 2]]))->toBe([
                ['user.name', 'Ada'],
                ['user.tags', '[]'],
                ['ids.0', 1],
                ['ids.1', 2],
            ]);
    });

    it('writes headers as name and value pairs', function (): void {
        expect(Format::headers(null))->toBe([])
            ->and(Format::headers(['accept' => ['text/html', 'application/json'], 'x-count' => 3]))->toBe([
                ['accept', 'text/html, application/json'],
                ['x-count', '3'],
            ]);
    });
});
