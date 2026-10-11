<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Dashboard\TabList;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\LensRegistry;

covers(TabList::class, Presentation::class);

describe(TabList::class, function (): void {
    $tabs = function (array $sections, array $order = [], array $problems = []): array {
        $capture = new Capture('c1', CaptureType::Request, microtime(true));
        $capture->sections = $sections;

        return app(TabList::class)->for($capture, $order, $problems);
    };

    it('gives sections with rows a tab, in the configured order first', function () use ($tabs): void {
        $result = $tabs(['cache' => [['n' => 1]], 'meta' => ['a' => 1], 'queries' => [['n' => 1], ['n' => 2]], 'events' => []], ['queries']);

        expect(array_column($result, 'key'))->toBe(['queries', 'cache'])
            ->and($result[0])->toMatchArray(['count' => 2, 'problems' => 0, 'errors' => 0]);
    });

    it('gives a section with data a tab when its lens has a tab view', function () use ($tabs): void {
        expect(array_column($tabs(['request' => ['method' => 'GET']]), 'key'))->toBe(['request']);
    });

    it('shows an included section on its host tab, and on its own without the host', function () use ($tabs): void {
        $auth = [['event' => 'login']];

        expect(array_column($tabs(['request' => ['method' => 'GET'], 'auth' => $auth], [], ['auth' => ['warnings' => 1, 'errors' => 0]]), 'problems', 'key'))->toBe(['request' => 1])
            ->and(array_column($tabs(['auth' => $auth]), 'key'))->toBe(['auth']);
    });

    it('counts the host tab by its own rows', function () use ($tabs): void {
        expect($tabs(['models' => [['n' => 1]], 'model_changes' => [['n' => 1], ['n' => 2]]]))->toBe([
            ['key' => 'models', 'label' => null, 'count' => 1, 'description' => app(LensRegistry::class)->descriptions()['models'] ?? null, 'problems' => 0, 'errors' => 0],
        ]);
    });
});
