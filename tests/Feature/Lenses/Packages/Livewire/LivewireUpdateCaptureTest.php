<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Livewire;

use ExeQue\Monocle\Lenses\Packages\Livewire\LivewireUpdateCapture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

covers(LivewireUpdateCapture::class);

describe(LivewireUpdateCapture::class, function (): void {
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $effects
     * @return array<string, mixed>|null
     */
    $card = function (array $before, array $after, array $effects = []): ?array {
        $request = Request::create('/livewire/update', 'POST', ['components' => [[
            'snapshot' => json_encode(['data' => $before, 'memo' => ['name' => 'cart', 'id' => 'c1']]),
        ]]]);
        $response = new JsonResponse(['components' => [[
            'snapshot' => json_encode(['data' => $after, 'memo' => ['name' => 'cart', 'id' => 'c1']]),
            'effects' => $effects,
        ]]]);

        return app(LivewireUpdateCapture::class)->capture($request, $response)[0];
    };

    it('ignores responses that are not a Livewire update payload', function (): void {
        $capture = app(LivewireUpdateCapture::class);
        $request = Request::create('/livewire/update', 'POST');

        expect($capture->capture($request, new Response('<html></html>')))->toBeNull()
            ->and($capture->capture($request, new JsonResponse(['redirect' => '/home'])))->toBeNull();
    });

    it('names events dispatched without params by their plain name', function () use ($card): void {
        expect($card([], [], ['dispatches' => ['cart-updated']])['items']['dispatched'])
            ->toBe([['name' => 'cart-updated', 'params' => null]]);
    });

    it('lists the items of a list that was emptied rather than the list itself', function () use ($card): void {
        $changes = $card(['items' => ['apple', 'pear']], ['items' => []])['items']['changes'];

        expect($changes)->toBe([
            ['field' => 'items.0', 'from' => 'apple', 'to' => null],
            ['field' => 'items.1', 'from' => 'pear', 'to' => null],
        ]);
    });

    it('leaves lists that stayed empty out and records removed empty lists', function () use ($card): void {
        $changes = $card(['tags' => [], 'notes' => []], ['tags' => []])['items']['changes'] ?? [];

        expect($changes)->toBe([['field' => 'notes', 'from' => [], 'to' => null]]);
    });
});
