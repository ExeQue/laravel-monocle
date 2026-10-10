<?php

declare(strict_types=1);

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\LensRegistry;

covers(
    ExeQue\Monocle\Lenses\AiLens::class,
    ExeQue\Monocle\Lenses\AuthorizationLens::class,
    ExeQue\Monocle\Lenses\CacheLens::class,
    ExeQue\Monocle\Lenses\CommandLens::class,
    ExeQue\Monocle\Lenses\DatabaseLens::class,
    ExeQue\Monocle\Lenses\DumpLens::class,
    ExeQue\Monocle\Lenses\EloquentLens::class,
    ExeQue\Monocle\Lenses\EventsLens::class,
    ExeQue\Monocle\Lenses\ExceptionsLens::class,
    ExeQue\Monocle\Lenses\FilesystemLens::class,
    ExeQue\Monocle\Lenses\HttpClientLens::class,
    ExeQue\Monocle\Lenses\LivewireLens::class,
    ExeQue\Monocle\Lenses\LogLens::class,
    ExeQue\Monocle\Lenses\MailLens::class,
    ExeQue\Monocle\Lenses\McpLens::class,
    ExeQue\Monocle\Lenses\MiddlewareLens::class,
    ExeQue\Monocle\Lenses\QueueLens::class,
    ExeQue\Monocle\Lenses\RedisLens::class,
    ExeQue\Monocle\Lenses\RequestLens::class,
    ExeQue\Monocle\Lenses\ScheduleLens::class,
    ExeQue\Monocle\Lenses\ViewsLens::class,
);

describe('lens descriptions', function (): void {
    it('gives every built-in lens a unique key and a description the agent can use', function (): void {
        $lenses = app(LensRegistry::class)->booted();
        $keys = array_map(fn (Lens $lens): string => $lens->key(), $lenses);

        expect($lenses)->toHaveCount(23)
            ->and($keys)->toBe(array_unique($keys));

        foreach ($lenses as $lens) {
            expect($lens->description())->toMatch('/^\S.{40,}/', $lens::class.' needs a real description')
                ->and($lens->key())->toMatch('/^[a-z_]+$/');
        }

        expect(app(LensRegistry::class)->descriptions())->toHaveKeys($keys);
    });
});
