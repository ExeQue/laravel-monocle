<?php

declare(strict_types=1);

namespace Tests\Arch;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Exceptions\MonocleExceptionInterface;
use ExeQue\Monocle\Lenses\ValidationLens;
use Symfony\Component\Finder\Finder;

// Recording where a call came from is what a profiler does.
arch()->preset()->php()->ignoring('debug_backtrace');

arch()->preset()->security();

arch('every file declares strict types')
    ->expect('ExeQue\Monocle')
    ->toUseStrictTypes();

arch('classes are final')
    ->expect('ExeQue\Monocle')
    ->classes()
    ->toBeFinal()
    ->ignoring(['ExeQue\Monocle\Exceptions', 'ExeQue\Monocle\Facades']);

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
    ->not->toUse(['Livewire', 'Inertia', 'Laravel\Ai', 'Laravel\Mcp', 'Laravel\Pennant', 'Spatie'])
    ->ignoring(['ExeQue\Monocle\Lenses\Packages', ValidationLens::class]);

test('every lens implements Lens and Advises', function (): void {
    $files = Finder::create()->files()->in(dirname(__DIR__, 2).'/src/Lenses')->name('*Lens.php');

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $class = 'ExeQue\Monocle\Lenses\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        expect($class)->toImplement(Lens::class)->toImplement(Advises::class);
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
