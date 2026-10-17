<?php

declare(strict_types=1);

namespace Tests\Arch;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\ExtendsLens;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Exceptions\MonocleExceptionInterface;
use ExeQue\Monocle\Lenses\Auth\ImpersonationLens;
use ExeQue\Monocle\Lenses\ValidationLens;
use ExeQue\Monocle\Support\Concerns\Immutable;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

// Recording where a call came from is what a profiler does. The demos call dump(),
// tempnam() and the like on purpose, to have something to record.
arch()->preset()->php()->ignoring(['debug_backtrace', 'Workbench\App\Http\Controllers\Demos']);

arch()->preset()->security()->ignoring('Workbench\App\Http\Controllers\Demos');

arch('every file declares strict types')
    ->expect('ExeQue\Monocle')
    ->toUseStrictTypes();

arch('classes are final')
    ->expect('ExeQue\Monocle')
    ->classes()
    ->toBeFinal()
    ->ignoring(['ExeQue\Monocle\Exceptions', 'ExeQue\Monocle\Facades', ImpersonationLens::class]);

arch('contracts are interfaces')
    ->expect('ExeQue\Monocle\Contracts')
    ->toBeInterfaces();

arch('enums are enums')
    ->expect('ExeQue\Monocle\Enums')
    ->toBeEnums();

arch('exceptions implement the package exception interface')
    ->expect('ExeQue\Monocle\Exceptions')
    ->classes()
    ->toImplement(MonocleExceptionInterface::class);

// ValidationLens recognises Livewire components as a validation source behind a class_exists() guard.
arch('core lenses do not depend on optional packages')
    ->expect('ExeQue\Monocle\Lenses')
    ->not->toUse(['Livewire', 'Inertia', 'Laravel\Ai', 'Laravel\Mcp', 'Laravel\Pennant', 'Prism', 'Spatie'])
    ->ignoring(['ExeQue\Monocle\Lenses\Packages', ValidationLens::class]);

// SplObjectStorage::contains(), attach() and detach() are deprecated in PHP 8.5 and log into the host app.
test('objects are keyed with WeakMap, not SplObjectStorage', function (): void {
    $files = Finder::create()->files()->in(dirname(__DIR__, 2).'/src')->name('*.php')->contains('SplObjectStorage');

    expect(iterator_to_array($files))->toBeEmpty();
});

test('immutables are final readonly classes built only through their own methods', function (): void {
    $files = Finder::create()->files()->in(dirname(__DIR__, 2).'/src')->name('*.php');
    $immutables = [];

    foreach ($files as $file) {
        $class = 'ExeQue\Monocle\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (class_exists($class) && in_array(Immutable::class, class_uses($class), true)) {
            $immutables[] = new ReflectionClass($class);
        }
    }

    expect($immutables)->not->toBeEmpty();

    foreach ($immutables as $immutable) {
        expect($immutable->isFinal())->toBeTrue("{$immutable->name} is not final")
            ->and($immutable->isReadOnly())->toBeTrue("{$immutable->name} is not readonly")
            ->and($immutable->getConstructor()?->isPrivate())->toBeTrue("{$immutable->name} has no private constructor")
            ->and($immutable->getMethod('make')->isStatic())->toBeTrue("{$immutable->name}::make() is not static")
            ->and($immutable->getMethod('make')->getNumberOfParameters())->toBe(0, "{$immutable->name}::make() takes arguments")
            ->and($immutable->getConstructor()?->getNumberOfRequiredParameters())->toBe(0, "{$immutable->name} cannot be made without arguments");
    }
});

test('every lens implements Lens and Advises', function (): void {
    $files = Finder::create()->files()->in(dirname(__DIR__, 2).'/src/Lenses')->name('*Lens.php');

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $class = 'ExeQue\Monocle\Lenses\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        expect($class)->toImplement(Lens::class);

        // An extension adds to another lens, whose advice covers it.
        if (! is_subclass_of($class, ExtendsLens::class)) {
            expect($class)->toImplement(Advises::class);
        }
    }
});

test('lenses and captures for other packages say which packages they need', function (): void {
    $files = Finder::create()->files()->in(dirname(__DIR__, 2).'/src/Lenses/Packages')->name(['*Lens.php', '*Capture.php']);

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $class = 'ExeQue\Monocle\Lenses\Packages\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        expect($class)->toImplement(RequiresPackages::class)
            ->and($class::packages())->not->toBeEmpty();
    }
});

test('package lenses live under the root namespace of the package they record', function (): void {
    $root = dirname(__DIR__, 2);
    $files = Finder::create()->files()->in($root.'/src/Lenses/Packages')->name('*Lens.php');

    foreach ($files as $file) {
        $author = explode('/', $file->getRelativePath())[0];
        $class = 'ExeQue\Monocle\Lenses\Packages\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        $manifest = $root.'/vendor/'.$class::packages()[0].'/composer.json';

        // Packages that are not dev dependencies (Nova is not on Packagist) cannot be checked.
        if (! is_file($manifest)) {
            continue;
        }

        $composer = json_decode((string) file_get_contents($manifest), true);
        $namespaces = array_map(fn (string $namespace): string => explode('\\', $namespace)[0], array_keys($composer['autoload']['psr-4'] ?? []));

        expect(in_array($author, $namespaces, true))->toBeTrue("{$class} records {$class::packages()[0]}, whose root namespace is not {$author}");
    }
});

test('every test file declares what it covers and mirrors its path in its namespace', function (): void {
    $root = dirname(__DIR__);
    $files = Finder::create()->files()->in([$root.'/Feature', $root.'/Unit'])->name('*Test.php');

    foreach ($files as $file) {
        $namespace = 'Tests\\'.str_replace('/', '\\', dirname(substr($file->getPathname(), strlen($root) + 1)));
        $contents = $file->getContents();

        expect($contents)
            ->toContain("namespace {$namespace};")
            ->toMatch('/^covers\(/m');
    }
});

test('internal classes are marked @internal and public ones are not', function (): void {
    $public = [
        'ExeQue\Monocle\Facades\\',
        'ExeQue\Monocle\Contracts\\',
        'ExeQue\Monocle\Data\\',
        'ExeQue\Monocle\Enums\\',
        'ExeQue\Monocle\Exceptions\\',
        'ExeQue\Monocle\Support\Masks\\',
        'ExeQue\Monocle\Support\Masker',
        'ExeQue\Monocle\Lenses\Auth\ImpersonationLens',
        'ExeQue\Monocle\MonocleManager',
        'ExeQue\Monocle\Eyepiece',
        'ExeQue\Monocle\Dashboard\Presentation',
        'ExeQue\Monocle\Lenses\Request\JsonCapture',
        'ExeQue\Monocle\Lenses\Request\TextCapture',
        'ExeQue\Monocle\Lenses\Packages\Inertia\InertiaCapture',
        'ExeQue\Monocle\Lenses\Packages\Livewire\LivewireUpdateCapture',
        'ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLCapture',
        'ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLHook',
        'ExeQue\Monocle\Lenses\Packages\GraphQL\Lighthouse\LighthouseHook',
        'ExeQue\Monocle\Lenses\Packages\GraphQL\Rebing\RebingHook',
    ];
    $files = Finder::create()->files()->in(dirname(__DIR__, 2).'/src')->name('*.php');

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $class = 'ExeQue\Monocle\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        $isLens = str_starts_with($class, 'ExeQue\Monocle\Lenses\\') && str_ends_with($class, 'Lens');
        $isPublic = $isLens || array_filter($public, fn (string $name): bool => $class === $name || (str_ends_with($name, '\\') && str_starts_with($class, $name))) !== [];
        $isInternal = str_contains((string) new ReflectionClass($class)->getDocComment(), '@internal');

        expect($isInternal)->toBe(! $isPublic, $isPublic ? "{$class} is public API but marked @internal" : "{$class} is internal but not marked @internal");
    }
});
