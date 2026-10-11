<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

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

    it('adds the library call that did the work on the way to user-land code', function (): void {
        $origin = Origin::capture('/app', 3, [], [
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Cache/Repository.php', 'line' => 5, 'class' => 'Illuminate\\Cache\\Repository', 'type' => '->', 'function' => 'get'],
            ['file' => '/app/vendor/gehrisandro/tailwind-merge-php/src/TailwindMerge.php', 'line' => 81, 'class' => 'TailwindMerge\\TailwindMerge', 'type' => '->', 'function' => 'merge'],
            ['file' => '/app/resources/views/button.blade.php', 'line' => 12, 'class' => 'Illuminate\\View\\ComponentAttributeBag', 'type' => '->', 'function' => 'twMerge'],
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php', 'line' => 9, 'class' => 'X', 'type' => '->', 'function' => 'y'],
        ]);

        expect($origin['file'])->toBe('resources/views/button.blade.php')
            ->and($origin['via'])->toBe(['file' => 'vendor/gehrisandro/tailwind-merge-php/src/TailwindMerge.php', 'line' => 81, 'call' => 'Illuminate\\View\\ComponentAttributeBag->twMerge'])
            ->and($origin)->not->toHaveKey('vendor');
    });

    it('names the library method the user-land code called as via, not the one deeper in the library', function (): void {
        $origin = Origin::capture('/app', 3, [], [
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/View/View.php', 'line' => 200, 'class' => 'Illuminate\\Cache\\Repository', 'type' => '->', 'function' => 'get'],
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/View/View.php', 'line' => 150, 'class' => 'Illuminate\\View\\View', 'type' => '->', 'function' => 'getContents'],
            ['file' => '/app/app/Http/Controllers/PageController.php', 'line' => 12, 'class' => 'Illuminate\\View\\View', 'type' => '->', 'function' => 'render'],
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Routing/Controller.php', 'line' => 9, 'class' => 'App\\Http\\Controllers\\PageController', 'type' => '->', 'function' => 'show'],
        ]);

        expect($origin['file'])->toBe('app/Http/Controllers/PageController.php')
            ->and($origin['via']['call'])->toBe('Illuminate\\View\\View->render');
    });

    it('looks past macros and magic methods to the method that does the work', function (): void {
        $origin = Origin::capture('/app', 3, [], [
            ['file' => '/app/vendor/gehrisandro/tailwind-merge-php/src/TailwindMerge.php', 'line' => 81, 'class' => 'Illuminate\\Cache\\Repository', 'type' => '->', 'function' => 'remember'],
            ['file' => '/app/vendor/gehrisandro/tailwind-merge-laravel/src/Provider.php', 'line' => 30, 'class' => 'TailwindMerge\\TailwindMerge', 'type' => '->', 'function' => 'merge'],
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Macroable/Traits/Macroable.php', 'line' => 120, 'class' => 'Illuminate\\View\\ComponentAttributeBag', 'type' => '->', 'function' => 'TailwindMerge\\{closure}'],
            ['file' => '/app/resources/views/button.blade.php', 'line' => 3, 'class' => 'Illuminate\\View\\ComponentAttributeBag', 'type' => '->', 'function' => '__call'],
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/View/Engines/PhpEngine.php', 'line' => 9, 'class' => 'X', 'type' => '->', 'function' => 'y'],
        ]);

        expect($origin['via']['call'])->toBe('TailwindMerge\\TailwindMerge->merge');
    });

    it('skips Monocle\'s own source wherever the package is installed', function (): void {
        $own = dirname(__DIR__, 3).'/src/Lenses/Middleware/TimedMiddleware.php';

        $origin = Origin::capture('/app', 3, [], [
            ['file' => $own, 'line' => 40, 'class' => 'Illuminate\\Pipeline\\Pipeline', 'type' => '->', 'function' => 'handle'],
            ['file' => '/app/app/Http/Middleware/Locale.php', 'line' => 12, 'class' => 'X', 'type' => '->', 'function' => 'y'],
        ]);

        expect($origin['file'])->toBe('app/Http/Middleware/Locale.php');
    });

    it('describes an origin for warnings with the library call it went through', function (): void {
        expect(Origin::describe(['file' => 'a.php', 'line' => 3, 'via' => ['call' => 'Lib->go']]))->toBe('a.php:3 via Lib->go')
            ->and(Origin::describe(['file' => 'a.php']))->toBe('a.php')
            ->and(Origin::describe(null))->toBeNull();
    });

    it('maps compiled blade files back to their source', function (): void {
        $base = sys_get_temp_dir().'/monocle-origin-'.bin2hex(random_bytes(4));
        $compiledDir = $base.'/storage/framework/views';
        mkdir($compiledDir, 0777, true);

        $compiled = $compiledDir.'/abc123.php';
        file_put_contents($compiled, "<?php return \ExeQue\\Monocle\\Support\\Origin::capture('{$base}', 1); /**PATH {$base}/resources/views/page.blade.php ENDPATH**/");

        $origin = include $compiled;

        expect($origin['file'])->toBe('resources/views/page.blade.php')
            ->and($origin['line'])->toBeNull();

        $orphan = $compiledDir.'/orphan.php';
        file_put_contents($orphan, "<?php return \ExeQue\\Monocle\\Support\\Origin::capture('{$base}', 1);");

        expect((include $orphan)['file'])->toEndWith('tests/Unit/Support/OriginTest.php');

        exec('rm -rf '.escapeshellarg($base));
    });
});
