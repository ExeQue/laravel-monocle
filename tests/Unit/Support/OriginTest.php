<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use ExeQue\Monocle\Support\Origin;
use ReflectionFunction;
use ReflectionMethod;
use RuntimeException;

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

    it('names a macro called through __call when it can be found among the registered macros', function (): void {
        require dirname(__DIR__, 2).'/Fixtures/vendor/acme/strings/macros.php';
        $closure = new ReflectionFunction((fn () => static::$macros['shout'])->call(new \Illuminate\Support\Stringable()));
        $vendor = '/app/vendor/laravel/framework/src/Illuminate/Macroable/Traits/Macroable.php';

        $origin = Origin::capture('/app', 3, [], [
            ['file' => $closure->getFileName(), 'line' => $closure->getStartLine() + 1, 'class' => 'X', 'type' => '->', 'function' => 'strtoupper'],
            ['file' => $vendor, 'line' => 120, 'class' => \Illuminate\Support\Stringable::class, 'type' => '->', 'function' => '{closure}'],
            ['file' => '/app/app/Http/Controllers/PageController.php', 'line' => 12, 'class' => \Illuminate\Support\Stringable::class, 'type' => '->', 'function' => '__call'],
            ['file' => '/app/vendor/laravel/framework/src/Illuminate/Routing/Controller.php', 'line' => 9, 'class' => 'X', 'type' => '->', 'function' => 'y'],
        ]);

        expect($origin['via']['call'])->toBe(\Illuminate\Support\Stringable::class.'->shout')
            ->and($origin['via']['line'])->toBe($closure->getStartLine());

        \Illuminate\Support\Stringable::flushMacros();
    });

    it('skips Monocle\'s own source wherever the package is installed', function (): void {
        $own = dirname(__DIR__, 3).'/src/Lenses/Middleware/TimedMiddleware.php';

        $origin = Origin::capture('/app', 3, [], [
            ['file' => $own, 'line' => 40, 'class' => 'Illuminate\\Pipeline\\Pipeline', 'type' => '->', 'function' => 'handle'],
            ['file' => '/app/app/Http/Middleware/Locale.php', 'line' => 12, 'class' => 'X', 'type' => '->', 'function' => 'y'],
        ]);

        expect($origin['file'])->toBe('app/Http/Middleware/Locale.php');
    });

    it('gives an origin at a known file and line, relative to the base path', function (): void {
        expect(Origin::at('/app/vendor/acme/Lib.php', 7, '/app'))->toBe(['file' => 'vendor/acme/Lib.php', 'line' => 7, 'call' => null, 'frames' => []])
            ->and(Origin::at('/elsewhere/Lib.php', null, '/app'))->toBe(['file' => '/elsewhere/Lib.php', 'line' => null, 'call' => null, 'frames' => []]);
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

    describe('relations and magic reads', function (): void {
        it('names the relation method a model property read loaded', function (): void {
            $post = (require dirname(__DIR__, 2).'/Fixtures/vendor/acme/origin/library.php')['post'];

            expect(fn () => $post->author)->toThrow(RuntimeException::class, 'Origin captured.');

            $origin = $post::$origin;

            expect($origin['file'])->toBe('tests/Unit/Support/OriginTest.php')
                ->and($origin['via']['file'])->toBe('tests/Fixtures/vendor/acme/origin/library.php')
                ->and($origin['via']['call'])->toBe($post::class.'->author')
                ->and($origin['via']['line'])->toBeInt();
        });

        it('keeps __get as via when no relation was loaded', function (): void {
            $magic = (require dirname(__DIR__, 2).'/Fixtures/vendor/acme/origin/library.php')['magic'];

            $origin = $magic->anything;

            expect($origin['file'])->toBe('tests/Unit/Support/OriginTest.php')
                ->and($origin['via']['call'])->toBe($magic::class.'->__get');
        });

        it('looks for the relation method in the arguments of a given trace', function (): void {
            $model = new class () extends \Illuminate\Database\Eloquent\Model {
                public function comments(): void
                {
                }
            };
            $read = fn (array ...$relationFrames): ?array => Origin::capture('/app', 3, [], [
                ['file' => '/app/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php', 'line' => 5, 'class' => 'X', 'type' => '->', 'function' => 'getAttribute'],
                ['file' => '/app/app/Http/Controllers/PostController.php', 'line' => 12, 'class' => 'App\\Models\\Post', 'type' => '->', 'function' => '__get'],
                ...$relationFrames,
            ])['via'];

            $viaGet = ['file' => 'vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php', 'line' => 5, 'call' => 'App\\Models\\Post->__get'];

            expect($read(
                ['function' => 'getRelationshipFromMethod', 'args' => [42]],
                ['function' => 'getRelationshipFromMethod', 'object' => $model, 'args' => ['comments']],
            ))->toMatchArray(['call' => $model::class.'->comments', 'line' => new ReflectionMethod($model, 'comments')->getStartLine()])
                ->and($read(['function' => 'getRelationshipFromMethod', 'args' => ['comments']]))->toBe($viaGet)
                ->and($read(['function' => 'getRelationshipFromMethod', 'class' => 'App\\Models\\Post', 'args' => ['missing']]))->toBe($viaGet)
                ->and($read())->toBe($viaGet);
        });
    });

    describe('incomplete frames', function (): void {
        it('reports no call for a frame without a function', function (): void {
            $origin = Origin::capture('/app', 3, [], [
                ['file' => '/app/app/Actions/Charge.php', 'line' => 3],
                ['file' => '/app/app/Http/Controllers/PayController.php', 'line' => 9],
            ]);

            expect($origin['call'])->toBeNull()
                ->and($origin['frames'][0]['call'])->toBeNull();
        });

        it('names no macro when the magic call cannot be traced to one', function (): void {
            $library = '/app/vendor/acme/lib/src/Lib.php';
            $user = '/app/app/Http/Controllers/PageController.php';

            $classless = Origin::capture('/app', 3, [], [
                ['file' => $library, 'line' => 3, 'class' => 'X', 'type' => '->', 'function' => 'run'],
                ['file' => $user, 'line' => 12, 'class' => 7, 'type' => '->', 'function' => '__call'],
            ]);
            $outermost = Origin::capture('/app', 3, [], [
                ['file' => $library, 'line' => 4, 'class' => 'X', 'type' => '->', 'function' => 'run'],
                ['file' => $user, 'line' => 12, 'class' => 'Illuminate\\Support\\Stringable', 'type' => '->', 'function' => '__call'],
            ]);

            expect($classless['via'])->toBe(['file' => 'vendor/acme/lib/src/Lib.php', 'line' => 3, 'call' => '7->__call'])
                ->and($outermost['via'])->toBe(['file' => 'vendor/acme/lib/src/Lib.php', 'line' => 4, 'call' => 'Illuminate\\Support\\Stringable->__call']);
        });

        it('skips registered macros that do not hold the calling line', function (): void {
            $macroable = (require dirname(__DIR__, 2).'/Fixtures/vendor/acme/origin/library.php')['macroable'];
            $first = new ReflectionFunction((fn () => static::$macros['first'])->call($macroable));
            $between = $first->getEndLine() + 1;

            $origin = Origin::capture('/base', 3, [], [
                ['file' => $first->getFileName(), 'line' => $between, 'class' => 'X', 'type' => '->', 'function' => 'helper'],
                ['file' => '/base/vendor/laravel/framework/src/Illuminate/Macroable/Traits/Macroable.php', 'line' => 120, 'class' => $macroable::class, 'type' => '->', 'function' => '{closure}'],
                ['file' => '/base/app/Http/Controllers/PageController.php', 'line' => 12, 'class' => $macroable::class, 'type' => '->', 'function' => '__call'],
                ['file' => '/base/vendor/laravel/framework/src/Illuminate/Routing/Controller.php', 'line' => 9, 'class' => 'X', 'type' => '->', 'function' => 'y'],
            ]);

            expect($origin['via'])->toBe(['file' => $first->getFileName(), 'line' => $between, 'call' => $macroable::class.'->{closure}']);
        });
    });

    it('reads the source of a compiled blade file once', function (): void {
        $base = sys_get_temp_dir().'/monocle-origin-'.bin2hex(random_bytes(4));
        $compiledDir = $base.'/storage/framework/views';
        mkdir($compiledDir, 0777, true);

        $compiled = $compiledDir.'/cached.php';
        file_put_contents($compiled, "<?php return \\ExeQue\\Monocle\\Support\\Origin::capture('{$base}', 1); /**PATH {$base}/resources/views/cached.blade.php ENDPATH**/");

        $first = include $compiled;
        file_put_contents($compiled, "<?php return \\ExeQue\\Monocle\\Support\\Origin::capture('{$base}', 1); /**PATH {$base}/resources/views/changed.blade.php ENDPATH**/");
        $second = include $compiled;

        expect($first['file'])->toBe('resources/views/cached.blade.php')
            ->and($second['file'])->toBe('resources/views/cached.blade.php');

        exec('rm -rf '.escapeshellarg($base));
    });
});
