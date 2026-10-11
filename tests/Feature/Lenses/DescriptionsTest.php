<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\LensRegistry;

covers(
    LensRegistry::class,
    \ExeQue\Monocle\Lenses\Packages\Laravel\AiLens::class,
    \ExeQue\Monocle\Lenses\AuthorizationLens::class,
    \ExeQue\Monocle\Lenses\CacheLens::class,
    \ExeQue\Monocle\Lenses\CommandLens::class,
    \ExeQue\Monocle\Lenses\DatabaseLens::class,
    \ExeQue\Monocle\Lenses\DumpLens::class,
    \ExeQue\Monocle\Lenses\EloquentLens::class,
    \ExeQue\Monocle\Lenses\EventsLens::class,
    \ExeQue\Monocle\Lenses\ExceptionsLens::class,
    \ExeQue\Monocle\Lenses\FilesystemLens::class,
    \ExeQue\Monocle\Lenses\HttpClientLens::class,
    \ExeQue\Monocle\Lenses\Packages\Livewire\LivewireLens::class,
    \ExeQue\Monocle\Lenses\LogLens::class,
    \ExeQue\Monocle\Lenses\MailLens::class,
    \ExeQue\Monocle\Lenses\Packages\Laravel\McpLens::class,
    \ExeQue\Monocle\Lenses\Packages\Prism\PrismLens::class,
    \ExeQue\Monocle\Lenses\ProcessLens::class,
    \ExeQue\Monocle\Lenses\MiddlewareLens::class,
    \ExeQue\Monocle\Lenses\QueueLens::class,
    \ExeQue\Monocle\Lenses\RedisLens::class,
    \ExeQue\Monocle\Lenses\RequestLens::class,
    \ExeQue\Monocle\Lenses\ScheduleLens::class,
    \ExeQue\Monocle\Lenses\ViewsLens::class,
);

describe('lens descriptions', function (): void {
    it('gives every built-in lens a unique key and a description the agent can use', function (): void {
        $lenses = app(LensRegistry::class)->booted();
        $keys = array_map(fn (Lens $lens): string => $lens->key(), $lenses);

        expect($lenses)->toHaveCount(29)
            ->and($keys)->toBe(array_unique($keys));

        foreach ($lenses as $lens) {
            expect($lens->description())->toMatch('/^\S.{40,}/', $lens::class.' needs a real description')
                ->and($lens->key())->toMatch('/^[a-z_]+$/');
        }

        expect(app(LensRegistry::class)->descriptions())->toHaveKeys($keys);
    });

    it('advises the agent on when to look at every built-in lens, in one sentence', function (): void {
        foreach (app(LensRegistry::class)->booted() as $lens) {
            expect($lens)->toBeInstanceOf(\ExeQue\Monocle\Contracts\Lenses\Advises::class)
                ->and($lens->advice())->toEndWith('.')
                ->and(substr_count($lens->advice(), '. '))->toBe(0, $lens::class.' gives more than one sentence');
        }
    });

    it('falls back to the first sentence of the description for lenses without advice', function (): void {
        $registry = new LensRegistry(app());
        $registry->register(\Tests\Fixtures\CounterLens::class);

        expect($registry->advice()['counter'])->toBe('Counts CounterEvent dispatches.')
            ->and($registry->advice()['queries'])->toStartWith('Look here first');
    });
});
