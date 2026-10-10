<?php

declare(strict_types=1);

use ExeQue\Monocle\Support\Origin;

covers(Origin::class);

describe(Origin::class, function (): void {
    it('reports the calling user-land file relative to the base path with a short call chain', function (): void {
        $origin = (fn (): ?array => Origin::capture(dirname(__DIR__, 3), 2))();

        expect($origin['file'])->toBe('tests/Unit/Support/OriginTest.php')
            ->and($origin['line'])->toBeInt()
            ->and($origin['frames'])->toHaveCount(1)
            ->and($origin['frames'][0]['file'])->toBe('tests/Unit/Support/OriginTest.php');
    });

    it('skips vendor frames unless a vendor prefix is included', function (): void {
        $viaVendor = fn (): ?array => collect([1])->map(fn (): ?array => Origin::capture(dirname(__DIR__, 3), 1))->first();
        $included = fn (): ?array => collect([1])->map(fn (): ?array => Origin::capture(dirname(__DIR__, 3), 1, ['laravel/framework']))->first();

        expect($viaVendor()['file'])->toBe('tests/Unit/Support/OriginTest.php')
            ->and($included()['frames'][0]['file'] ?? $included()['file'])->toContain('vendor/laravel/framework');
    });

    it('falls back to the first library call outside the database and cache plumbing', function (): void {
        $base = '/app';
        $frame = fn (string $file, string $class): array => ['file' => $base.'/vendor/'.$file, 'line' => 10, 'class' => $class, 'type' => '->', 'function' => 'handle'];

        $origin = Origin::capture($base, 3, [], [
            $frame('laravel/framework/src/Illuminate/Database/Connection.php', 'Illuminate\\Database\\Connection'),
            $frame('laravel/framework/src/Illuminate/Database/Query/Builder.php', 'Illuminate\\Database\\Query\\Builder'),
            $frame('livewire/livewire/src/Mechanisms/HandleComponents.php', 'Livewire\\Mechanisms\\HandleComponents'),
            $frame('laravel/framework/src/Illuminate/Pipeline/Pipeline.php', 'Illuminate\\Pipeline\\Pipeline'),
        ]);

        expect($origin)->toBe([
            'file' => 'vendor/livewire/livewire/src/Mechanisms/HandleComponents.php',
            'line' => 10,
            'call' => 'Illuminate\\Pipeline\\Pipeline->handle',
            'frames' => [],
            'vendor' => true,
        ])->and(Origin::capture($base, 3, [], [$frame('exeque/laravel-monocle/src/Eyepiece.php', 'X')]))->toBeNull();
    });

    it('maps compiled blade files back to their source', function (): void {
        $base = sys_get_temp_dir().'/monocle-origin-'.bin2hex(random_bytes(4));
        $compiledDir = $base.'/storage/framework/views';
        mkdir($compiledDir, 0777, true);

        $compiled = $compiledDir.'/abc123.php';
        file_put_contents($compiled, "<?php return ExeQue\\Monocle\\Support\\Origin::capture('{$base}', 1); /**PATH {$base}/resources/views/page.blade.php ENDPATH**/");

        $origin = include $compiled;

        expect($origin['file'])->toBe('resources/views/page.blade.php')
            ->and($origin['line'])->toBeNull();

        $orphan = $compiledDir.'/orphan.php';
        file_put_contents($orphan, "<?php return ExeQue\\Monocle\\Support\\Origin::capture('{$base}', 1);");

        expect((include $orphan)['file'])->toEndWith('tests/Unit/Support/OriginTest.php');

        exec('rm -rf '.escapeshellarg($base));
    });
});
