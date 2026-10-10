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
            ->and($presenter->link('select * from a.php'))->toBeNull()
            ->and($presenter->link('App\\Missing\\Thing'))->toBeNull()
            ->and($presenter->link(42))->toBeNull()
            ->and($presenter->row(['class' => TimelinePresenter::class, 'n' => 1])['_links'])->toHaveKey('class')
            ->and($presenter->originFromString('app/X.php:3')['link'])->toBe('vscode://file/app/app/X.php:3')
            ->and($presenter->originFromString('app/X.php')['link'])->toBe('vscode://file/app/app/X.php:1');
    });

    it('links nothing without an editor', function (): void {
        $presenter = new TimelinePresenter(new EditorLinks(null, '/app'));

        expect($presenter->link(TimelinePresenter::class))->toBeNull()
            ->and($presenter->row(['class' => TimelinePresenter::class]))->not->toHaveKey('_links')
            ->and($presenter->origin(['file' => 'a.php', 'line' => 1, 'call' => TimelinePresenter::class.'->x'])['call_link'])->toBeNull();
    });
});
