<?php

declare(strict_types=1);

use ExeQue\Monocle\Editor\Editor;
use ExeQue\Monocle\Editor\EditorLinks;
use ExeQue\Monocle\Editor\EditorSettings;

covers(Editor::class, EditorLinks::class, EditorSettings::class);

describe(EditorLinks::class, function (): void {
    $links = fn (?string $editor, array $paths = [], ?string $distro = null, string $base = '/var/www/html', ?string $project = null): EditorLinks => new EditorLinks(new EditorSettings($editor, $paths, $distro, $project), $base);

    describe('without an editor', function () use ($links): void {
        it('makes nothing clickable', function () use ($links): void {
            $none = new EditorLinks(null, '/var/www/html');

            expect($none->enabled())->toBeFalse()
                ->and($none->file('app/Models/User.php', 3))->toBeNull()
                ->and($none->class(Editor::class))->toBeNull()
                ->and($links(null)->file('app/Models/User.php'))->toBeNull()
                ->and($links('notepad')->file('app/Models/User.php'))->toBeNull();
        });
    });

    describe('links', function () use ($links): void {
        it('builds the link format of every editor', function (string $editor, string $expected) use ($links): void {
            expect($links($editor)->file('app/Models/User.php', 12))->toBe($expected);
        })->with([
            'phpstorm' => ['phpstorm', 'phpstorm://open?file=%2Fvar%2Fwww%2Fhtml%2Fapp%2FModels%2FUser.php&line=12'],
            'idea' => ['idea', 'idea://open?file=%2Fvar%2Fwww%2Fhtml%2Fapp%2FModels%2FUser.php&line=12'],
            'vscode' => ['vscode', 'vscode://file/var/www/html/app/Models/User.php:12'],
            'cursor' => ['cursor', 'cursor://file/var/www/html/app/Models/User.php:12'],
            'windsurf' => ['windsurf', 'windsurf://file/var/www/html/app/Models/User.php:12'],
            'zed' => ['zed', 'zed://file/var/www/html/app/Models/User.php:12'],
            'sublime' => ['sublime', 'subl://open?url=file%3A%2F%2F%2Fvar%2Fwww%2Fhtml%2Fapp%2FModels%2FUser.php&line=12'],
            'nova' => ['nova', 'nova://open?path=%2Fvar%2Fwww%2Fhtml%2Fapp%2FModels%2FUser.php&line=12'],
        ]);

        it('defaults to line 1 and keeps absolute paths', function () use ($links): void {
            expect($links('vscode')->file('/opt/vendor/x.php'))->toBe('vscode://file/opt/vendor/x.php:1');
        });

        it('opens files inside WSL from an editor on Windows', function () use ($links): void {
            expect($links('phpstorm', distro: 'Ubuntu', base: '/home/me/app')->file('app/X.php', 4))
                ->toBe('phpstorm://open?file='.rawurlencode('\\\\wsl.localhost\\Ubuntu\\home\\me\\app\\app\\X.php').'&line=4')
                ->and($links('vscode', distro: 'Ubuntu', base: '/home/me/app')->file('app/X.php', 4))->toBe('vscode://vscode-remote/wsl+Ubuntu/home/me/app/app/X.php:4')
                ->and($links('cursor', distro: 'Ubuntu', base: '/home/me/app')->file('app/X.php', 4))->toBe('cursor://vscode-remote/wsl+Ubuntu/home/me/app/app/X.php:4')
                ->and($links('zed', distro: 'Ubuntu', base: '/home/me/app')->file('app/X.php', 4))->toBe('zed://file/\\\\wsl.localhost\\Ubuntu\\home\\me\\app\\app\\X.php:4');
        });

        it('handles projects on a Windows drive', function () use ($links): void {
            expect($links('vscode', base: 'C:\\code\\app')->file('app\\X.php', 2))->toBe('vscode://file/C:/code/app/app/X.php:2')
                ->and($links('phpstorm', base: 'C:\\code\\app')->file('C:\\code\\app\\app\\X.php', 2))->toBe('phpstorm://open?file='.rawurlencode('C:/code/app/app/X.php').'&line=2');
        });

        it('opens JetBrains IDEs through Toolbox when a project is set, counting lines from 0', function () use ($links): void {
            expect($links('phpstorm', ['/var/www/html' => '/home/me/app'], 'Ubuntu', project: 'eazy.photo')->file('app/My File.php', 12))
                ->toBe('jetbrains://php-storm/navigate/reference?project=eazy.photo&path=app/My%20File.php:11')
                ->and($links('idea', project: 'x')->file('/var/www/html/a.php'))->toBe('jetbrains://idea/navigate/reference?project=x&path=a.php:0')
                ->and($links('phpstorm', project: 'x')->file('/opt/elsewhere.php', 3))->toBe('phpstorm://open?file=%2Fopt%2Felsewhere.php&line=3')
                ->and($links('vscode', project: 'x')->file('a.php'))->toBe('vscode://file/var/www/html/a.php:1')
                ->and(Editor::VsCode->toolboxLink('a.php', 1, 'x'))->toBeNull();
        });

        it('fills a custom URL template', function () use ($links): void {
            expect($links('my://open?f={file}&l={line}')->file('app/X.php', 9))->toBe('my://open?f=/var/www/html/app/X.php&l=9')
                ->and($links('my://{distro}/{linux_file}?w={file}:{line}', distro: 'Debian', base: '/srv')->file('a.php', 1))
                ->toBe('my://Debian//srv/a.php?w=\\\\wsl.localhost\\Debian\\srv\\a.php:1');
        });

        it('links classes to the file they are declared in', function () use ($links): void {
            $link = $links('vscode', base: '/')->class(Editor::class);

            expect($link)->toStartWith('vscode://file/')
                ->toContain('src/Editor/Editor.php:')
                ->and($links('vscode')->class('App\\Missing'))->toBeNull();
        });
    });

    describe('path mappings', function () use ($links): void {
        it('swaps the longest matching prefix', function () use ($links): void {
            $mapped = $links('vscode', ['/var/www' => '/a', '/var/www/html/' => '/home/me/app/']);

            expect($mapped->file('app/X.php'))->toBe('vscode://file/home/me/app/app/X.php:1')
                ->and($mapped->file('/var/www/other.php'))->toBe('vscode://file/a/other.php:1')
                ->and($mapped->file('/var/wwwx/y.php'))->toBe('vscode://file/var/wwwx/y.php:1');
        });

        it('maps container paths to a Windows drive', function () use ($links): void {
            expect($links('vscode', ['/var/www/html' => 'C:\\code\\app'])->file('app/X.php'))->toBe('vscode://file/C:/code/app/app/X.php:1');
        });
    });
});

describe(EditorSettings::class, function (): void {
    beforeEach(function (): void {
        $this->file = sys_get_temp_dir().'/monocle-editor-'.bin2hex(random_bytes(4)).'.json';
    });

    afterEach(function (): void {
        @unlink($this->file);
    });

    it('reads .monocle.json and ignores what it cannot use', function (): void {
        file_put_contents($this->file, json_encode(['editor' => 'zed', 'paths' => ['/a' => '/b', '/c' => '', '/d' => 3], 'wsl' => ['distro' => 'Ubuntu']]));

        $settings = EditorSettings::fromFile($this->file);

        expect($settings->editor)->toBe('zed')
            ->and($settings->paths)->toBe(['/a' => '/b'])
            ->and($settings->wslDistro)->toBe('Ubuntu')
            ->and(EditorSettings::fromFile($this->file.'.missing'))->toBeNull();

        file_put_contents($this->file, '{ nope');

        expect(EditorSettings::fromFile($this->file))->toBeNull()
            ->and(EditorSettings::fromArray(['editor' => '', 'wsl' => ['distro' => '']]))->toEqual(new EditorSettings());
    });

    it('serializes paths as an object and leaves out an unset WSL distro', function (): void {
        expect(json_encode((new EditorSettings('vscode'))->toArray()))->toBe('{"editor":"vscode","paths":{}}')
            ->and(EditorSettings::fromArray(['project' => 'app'])->project)->toBe('app')
            ->and((new EditorSettings('phpstorm', [], null, 'app'))->toArray()['project'])->toBe('app')
            ->and((new EditorSettings('vscode', [], 'Ubuntu'))->toArray()['wsl'])->toBe(['distro' => 'Ubuntu']);
    });
});

describe(Editor::class, function (): void {
    it('has a label for every editor', function (): void {
        expect(array_map(fn (Editor $editor): string => $editor->label(), Editor::cases()))->each->not->toBeEmpty();
    });
});
