<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use ExeQue\Monocle\Dashboard\TimelinePresenter;
use ExeQue\Monocle\Editor\EditorLinks;
use ExeQue\Monocle\Editor\EditorSettings;
use ReflectionClass;
use ReflectionMethod;

covers(TimelinePresenter::class);

describe(TimelinePresenter::class, function (): void {
    it('links every class name and file path when an editor is set', function (): void {
        $presenter = new TimelinePresenter(new EditorLinks(new EditorSettings('vscode'), '/app'));

        expect($presenter->link(TimelinePresenter::class))->toStartWith('vscode://file/')->toContain('TimelinePresenter.php:')
            ->and($presenter->link('\\'.TimelinePresenter::class.'->present'))->toContain('TimelinePresenter.php:')
            ->and($presenter->link(TimelinePresenter::class.'@present'))->toContain('TimelinePresenter.php:')
            ->and($presenter->link(TimelinePresenter::class.'#12'))->toContain('TimelinePresenter.php:')
            ->and($presenter->link('\\'.TimelinePresenter::class.'->present'))->toEndWith(':'.new ReflectionMethod(TimelinePresenter::class, 'present')->getStartLine())
            ->and($presenter->link(TimelinePresenter::class.'::present'))->not->toEndWith(':'.new ReflectionClass(TimelinePresenter::class)->getStartLine())
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
            ->and(new TimelinePresenter(new EditorLinks(null, '/app'))->textLinks($warning))->toBe([])
            ->and($presenter->textLinks('HTTP call failed: POST https://hooks.example.invalid/published (see https://curl.se/libcurl/c/libcurl-errors.html)'))->toBe([]);
    });

    it('passes on only colours the dashboard can draw', function (): void {
        $presenter = new TimelinePresenter(new EditorLinks(null, '/app'));
        $capture = \ExeQue\Monocle\Data\Capture::fromArray([
            'id' => 'c1', 'type' => 'request', 'started_at' => 0.0, 'ended_at' => 0.01,
            'spans' => [
                ['id' => 'a', 'kind' => 'user', 'name' => 'a', 'start' => 0, 'end' => 1, 'color' => 'teal'],
                ['id' => 'b', 'kind' => 'user', 'name' => 'b', 'start' => 1, 'end' => 2, 'color' => '#abc'],
                ['id' => 'c', 'kind' => 'user', 'name' => 'c', 'start' => 2, 'end' => 3, 'color' => 'red;x:url(y)'],
            ],
        ]);

        expect(array_column($presenter->present($capture), 'color', 'name'))->toBe(['a' => 'teal', 'b' => '#abc', 'c' => null]);
    });

    it('hangs requests sent in one pool under a pool node', function (): void {
        $presenter = new TimelinePresenter(new EditorLinks(null, '/app'));
        $capture = \ExeQue\Monocle\Data\Capture::fromArray([
            'id' => 'c1', 'type' => 'request', 'started_at' => 0.0, 'ended_at' => 0.1,
            'spans' => [['id' => 'h', 'kind' => 'phase', 'name' => 'handle', 'start' => 0, 'end' => 100]],
            'sections' => ['http' => [
                ['method' => 'GET', 'url' => 'https://a.test/1', 'time' => 10, 'duration' => 20, 'failed' => false, 'pool' => 1],
                ['method' => 'GET', 'url' => 'https://a.test/2', 'time' => 11, 'duration' => 30, 'failed' => true, 'pool' => 1],
                ['method' => 'GET', 'url' => 'https://a.test/3', 'time' => 50, 'duration' => 5, 'failed' => false, 'pool' => null],
            ]],
        ]);

        $handle = $presenter->present($capture)[0];
        $pool = $handle['children'][0];

        expect(array_column($handle['children'], 'kind'))->toBe(['http.pool', 'http'])
            ->and($pool['name'])->toBe('pool #1, 2 requests')
            ->and([$pool['s'], $pool['e']])->toBe([10.0, 41.0])
            ->and($pool['d']['meta'])->toBe(['requests' => 2, 'failed' => 1])
            ->and(array_column($pool['children'], 'name'))->toBe(['GET https://a.test/1', 'GET https://a.test/2']);
    });

    it('links the classes of a recorded exception and the ones it wraps', function (): void {
        $presenter = new TimelinePresenter(new EditorLinks(new EditorSettings('vscode'), '/app'));
        $row = $presenter->row(['exception' => [
            'class' => TimelinePresenter::class,
            'message' => 'outer',
            'previous' => [['class' => EditorLinks::class, 'message' => 'inner', 'file' => 'app/X.php:3']],
        ]]);

        expect($row['exception']['link'])->toContain('TimelinePresenter.php:')
            ->and($row['exception']['previous'][0]['link'])->toContain('EditorLinks.php:')
            ->and($row['exception']['previous'][0]['file_link'])->toBe('vscode://file/app/app/X.php:3');
    });

    it('links rule classes and closures in validation rules', function (): void {
        $presenter = new TimelinePresenter(new EditorLinks(new EditorSettings('vscode'), '/app'));
        $row = $presenter->row(['rules' => [
            'slug' => 'required|'.EditorLinks::class.' {"max":40}',
            'body' => 'string|Closure (routes/web.php:57)',
        ]]);

        expect($row['_links']['rules'])->toHaveKeys(['Closure (routes/web.php:57)', EditorLinks::class.' {"max":40}'])
            ->and($row['_links']['rules']['Closure (routes/web.php:57)'])->toBe('vscode://file/app/routes/web.php:57')
            ->and($row['_links']['rules'])->not->toHaveKey('required')
            ->and($presenter->link('Closure (routes/web.php:12)'))->toBe('vscode://file/app/routes/web.php:12');
    });

    it('links nothing without an editor', function (): void {
        $presenter = new TimelinePresenter(new EditorLinks(null, '/app'));

        expect($presenter->link(TimelinePresenter::class))->toBeNull()
            ->and($presenter->row(['class' => TimelinePresenter::class]))->not->toHaveKey('_links')
            ->and($presenter->origin(['file' => 'a.php', 'line' => 1, 'call' => TimelinePresenter::class.'->x'])['call_link'])->toBeNull();
    });
    it('links class names and paths inside nested values by their dotted key', function (): void {
        $presenter = new TimelinePresenter(new EditorLinks(new EditorSettings('vscode'), '/app'));

        $row = $presenter->row(['payload' => ['order' => TimelinePresenter::class.'#12', 'meta' => ['by' => TimelinePresenter::class], 'count' => 3]]);

        expect($row['_links']['payload'])->toHaveKeys(['order', 'meta.by'])
            ->and($row['_links']['payload'])->not->toHaveKey('count');
    });

    it('places timed rows under their span and labels them by section', function (): void {
        $presenter = new TimelinePresenter(new EditorLinks(null, '/app'));
        $capture = \ExeQue\Monocle\Data\Capture::fromArray([
            'id' => 'c1', 'type' => 'request', 'started_at' => 0.0, 'ended_at' => 0.1,
            'spans' => [
                ['id' => 'h', 'kind' => 'phase', 'name' => 'handle', 'start' => 0, 'end' => 100],
                ['id' => 'orphan', 'kind' => 'user', 'name' => 'orphan', 'parent_id' => 'gone', 'start' => 0, 'end' => 5],
                ['id' => 'unstarted', 'kind' => 'user', 'name' => 'unstarted', 'start' => null, 'end' => null],
            ],
            'sections' => [
                'queries' => [['sql' => 'select 1', 'duration' => 1]],
                'redis' => [['command' => 'get', 'parameters' => ['key', ['nested']], 'time' => 10, 'duration' => 1]],
                'filesystem' => [['op' => 'put', 'disk' => 'local', 'path' => 'a.txt', 'time' => 20, 'duration' => 1]],
                'http' => [['method' => 'GET', 'url' => 'https://a.test', 'pool' => 1]],
            ],
        ]);

        $roots = array_column($presenter->present($capture), null, 'id');

        expect(array_keys($roots))->toEqualCanonicalizing(['h', 'orphan', 'unstarted'])
            ->and(array_column($roots['h']['children'], 'name'))->toBe(['GET key ...', 'put local:a.txt'])
            ->and($roots['orphan']['children'])->toBe([])
            ->and($roots['unstarted']['children'])->toBe([]);
    });

    it('splits and links stack frames and wrapped exceptions in a row', function (): void {
        $presenter = new TimelinePresenter(new EditorLinks(new EditorSettings('vscode'), '/app'));

        $row = $presenter->row([
            'origin' => ['file' => 'app/A.php', 'line' => 3],
            'frames' => ['app/B.php:4 App\\Missing->handle', 'app/C.php:5', ['file' => 'x']],
            'previous' => [['class' => 'App\\Missing', 'message' => 'wrapped'], 'loose'],
            'rules' => ['name' => 'required', 'tags' => ['nested']],
            'blank' => '',
            'long' => str_repeat('a', 501).'.php',
        ]);

        expect($row['origin']['link'])->toBe('vscode://file/app/app/A.php:3')
            ->and($row['frames'][0])->toBe(['file' => 'app/B.php:4', 'call' => 'App\\Missing->handle', 'link' => 'vscode://file/app/app/B.php:4', 'call_link' => null])
            ->and($row['frames'][1])->toBe(['file' => 'app/C.php:5', 'call' => null, 'link' => 'vscode://file/app/app/C.php:5', 'call_link' => null])
            ->and($row['frames'][2])->toBe(['file' => 'x'])
            ->and($row['previous'][0])->toMatchArray(['class' => 'App\\Missing', 'link' => null, 'file_link' => null])
            ->and($row['previous'][1])->toBe('loose')
            ->and($row)->not->toHaveKey('_links');
    });
});
