<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use ExeQue\Monocle\Support\ThrowableChain;
use LogicException;
use RuntimeException;

covers(ThrowableChain::class);

describe(ThrowableChain::class, function (): void {
    it('describes a throwable and the exceptions it wraps, outermost first', function (): void {
        $root = new LogicException('root cause');
        $exception = new RuntimeException('outer', 0, new RuntimeException('middle', 0, $root));

        expect(ThrowableChain::describe($exception))->toBe([
            'class' => RuntimeException::class,
            'message' => 'outer',
            'previous' => [
                ['class' => RuntimeException::class, 'message' => 'middle'],
                ['class' => LogicException::class, 'message' => 'root cause'],
            ],
        ]);
    });

    it('adds the file when asked and stops after ten previous exceptions', function (): void {
        $exception = new RuntimeException('0');

        foreach (range(1, 15) as $i) {
            $exception = new RuntimeException((string) $i, 0, $exception);
        }

        $described = ThrowableChain::describe($exception, fn (string $file): string => basename($file));

        expect($described['file'])->toStartWith('ThrowableChainTest.php:')
            ->and($described['previous'])->toHaveCount(10)
            ->and($described['previous'][0]['file'])->toStartWith('ThrowableChainTest.php:');
    });
});
