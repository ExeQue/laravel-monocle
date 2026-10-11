<?php

declare(strict_types=1);

use ExeQue\Monocle\Dashboard\TimelinePresenter;
use ExeQue\Monocle\Editor\EditorLinks;
use ExeQue\Monocle\Editor\EditorSettings;

covers(TimelinePresenter::class);

describe(TimelinePresenter::class, function (): void {
    it('links every class name and file path when an editor is set', function (): void {
        $presenter = new TimelinePresenter(new EditorLinks(new EditorSettings('vscode'), '/app'));

        expect($presenter->link(TimelinePresenter::class))->toStartWith('vscode://file/')->toContain('TimelinePresenter.php:')
            ->and($presenter->link('\\'.TimelinePresenter::class.'->present'))->toContain('TimelinePresenter.php:')
            ->and($presenter->link(TimelinePresenter::class.'@present'))->toContain('TimelinePresenter.php:')
            ->and($presenter->link('app/Models/User.php:12'))->toBe('vscode://file/app/app/Models/User.php:12')
            ->and($presenter->link('resources\\views\\page.blade.php'))->toBe('vscode://file/app/resources/views/page.blade.php:1')
            ->and($presenter->link('https://example.com/a.js'))->toBeNull()
            ->and($presenter->link('storage/framework/views/abc.blade.php'))->toBeNull()
            ->and($presenter->link('select * from a.php'))->toBeNull()
            ->and($presenter->link('App\\Missing\\Thing'))->toBeNull()
            ->and($presenter->link(42))->toBeNull()
            ->and($presenter->row(['class' => TimelinePresenter::class, 'n' => 1])['_links'])->toHaveKey('class')
            ->and($presenter->originFromString('app/X.php:3')['link'])->toBe('vscode://file/app/app/X.php:3')
            ->and($presenter->originFromString('app/X.php')['link'])->toBe('vscode://file/app/app/X.php:1')
            ->and($presenter->originFromString('app/X.php:4 via Lib->go')['via']['call'])->toBe('Lib->go')
            ->and($presenter->originFromString('app/X.php:4 via Lib->go')['line'])->toBe(4);
    });

    it('links the paths and classes mentioned in a warning', function (): void {
        $presenter = new TimelinePresenter(new EditorLinks(new EditorSettings('vscode'), '/app'));
        $warning = 'Possible N+1: 12 x select * from `photos` (app/Livewire/Gallery.php:44 via '.TimelinePresenter::class.'->present). Run php artisan x.';

        expect($presenter->textLinks($warning))->toBe([
            'app/Livewire/Gallery.php:44' => 'vscode://file/app/app/Livewire/Gallery.php:44',
            TimelinePresenter::class.'->present' => $presenter->link(TimelinePresenter::class.'->present'),
        ])
            ->and((new TimelinePresenter(new EditorLinks(null, '/app')))->textLinks($warning))->toBe([]);
    });

    it('passes on only colours the dashboard can draw', function (): void {
        $presenter = new TimelinePresenter(new EditorLinks(null, '/app'));
        $capture = ExeQue\Monocle\Data\Capture::fromArray([
            'id' => 'c1', 'type' => 'request', 'started_at' => 0.0, 'ended_at' => 0.01,
            'spans' => [
                ['id' => 'a', 'kind' => 'user', 'name' => 'a', 'start' => 0, 'end' => 1, 'color' => 'teal'],
                ['id' => 'b', 'kind' => 'user', 'name' => 'b', 'start' => 1, 'end' => 2, 'color' => '#abc'],
                ['id' => 'c', 'kind' => 'user', 'name' => 'c', 'start' => 2, 'end' => 3, 'color' => 'red;x:url(y)'],
            ],
        ]);

        expect(array_column($presenter->present($capture), 'color', 'name'))->toBe(['a' => 'teal', 'b' => '#abc', 'c' => null]);
    });

    it('links nothing without an editor', function (): void {
        $presenter = new TimelinePresenter(new EditorLinks(null, '/app'));

        expect($presenter->link(TimelinePresenter::class))->toBeNull()
            ->and($presenter->row(['class' => TimelinePresenter::class]))->not->toHaveKey('_links')
            ->and($presenter->origin(['file' => 'a.php', 'line' => 1, 'call' => TimelinePresenter::class.'->x'])['call_link'])->toBeNull();
    });
});
