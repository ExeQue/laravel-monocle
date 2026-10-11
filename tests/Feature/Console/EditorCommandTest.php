<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use ExeQue\Monocle\Console\EditorCommand;
use ExeQue\Monocle\Install\Environment;

covers(EditorCommand::class);

describe(EditorCommand::class, function (): void {
    beforeEach(function (): void {
        $this->dir = sys_get_temp_dir().'/monocle-editor-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->app->setBasePath($this->dir);

        $this->kernel = $this->dir.'/osrelease';
        file_put_contents($this->kernel, '6.8.0-generic');
        $this->environment = fn (string $os, string $kernel, array $env = []) => $this->app->instance(Environment::class, new Environment($os, [$this->kernel], $env)) && file_put_contents($this->kernel, $kernel);
        ($this->environment)('Linux', '6.8.0-generic');

        $this->settings = fn (): array => json_decode((string) file_get_contents($this->dir.'/.monocle.json'), true);
        $this->editors = ['phpstorm' => 'PhpStorm', 'idea' => 'IntelliJ IDEA', 'vscode' => 'VS Code', 'cursor' => 'Cursor', 'windsurf' => 'Windsurf', 'zed' => 'Zed', 'sublime' => 'Sublime Text', 'nova' => 'Nova', 'custom' => 'Other (URL template)'];
    });

    afterEach(function (): void {
        exec('rm -rf '.escapeshellarg($this->dir));
    });

    it('writes .monocle.json from flags and ignores it in git', function (): void {
        $this->artisan('monocle:editor', ['--editor' => 'phpstorm', '--map' => [$this->dir.':/home/me/app'], '--no-interaction' => true])
            ->expectsOutputToContain('phpstorm://open?file=%2Fhome%2Fme%2Fapp%2Fartisan&line=1')
            ->assertSuccessful();

        expect(($this->settings)())->toBe(['editor' => 'phpstorm', 'paths' => [$this->dir => '/home/me/app']])
            ->and(file_get_contents($this->dir.'/.gitignore'))->toContain("\n/.monocle.json\n");
    });

    it('asks where the editor runs under WSL and which distro holds the project', function (): void {
        ($this->environment)('Linux', '5.15.167.4-microsoft-standard-WSL2', ['WSL_DISTRO_NAME' => 'Ubuntu']);

        $this->artisan('monocle:editor')
            ->expectsChoice('Which editor do you use?', 'vscode', $this->editors)
            ->expectsChoice('Where does your editor run?', 'windows', [
                'windows' => 'On Windows, with the project inside WSL',
                'wsl' => 'Inside WSL (WSLg, JetBrains Gateway, VS Code server)',
                'windows-drive' => 'On Windows, with the project on a Windows drive',
            ])
            ->expectsQuestion('Which WSL distro holds the project?', 'Ubuntu')
            ->expectsOutputToContain('vscode://vscode-remote/wsl+Ubuntu')
            ->assertSuccessful();

        expect(($this->settings)())->toMatchArray(['editor' => 'vscode', 'wsl' => ['distro' => 'Ubuntu']]);
    });

    it('asks for the JetBrains project when PhpStorm runs on Windows', function (): void {
        ($this->environment)('Linux', '5.15.167.4-microsoft-standard-WSL2', ['WSL_DISTRO_NAME' => 'Ubuntu']);
        mkdir($this->dir.'/.idea');
        file_put_contents($this->dir.'/.idea/modules.xml', '<module fileurl="file://$PROJECT_DIR$/.idea/eazy.photo.iml" filepath="$PROJECT_DIR$/.idea/eazy.photo.iml" />');

        $this->artisan('monocle:editor')
            ->expectsChoice('Which editor do you use?', 'phpstorm', $this->editors)
            ->expectsChoice('Where does your editor run?', 'windows', [
                'windows' => 'On Windows, with the project inside WSL',
                'wsl' => 'Inside WSL (WSLg, JetBrains Gateway, VS Code server)',
                'windows-drive' => 'On Windows, with the project on a Windows drive',
            ])
            ->expectsQuestion('Which WSL distro holds the project?', 'Ubuntu')
            ->expectsQuestion('Project name in your JetBrains IDE', 'eazy.photo')
            ->expectsOutputToContain('jetbrains://php-storm/navigate/reference?project=eazy.photo&path=artisan:0')
            ->assertSuccessful();

        expect(($this->settings)())->toMatchArray(['editor' => 'phpstorm', 'project' => 'eazy.photo']);

        $this->artisan('monocle:editor', ['--project' => '', '--no-interaction' => true])->assertSuccessful();

        expect(($this->settings)())->not->toHaveKey('project');
    });

    it('detects the JetBrains project name without prompting', function (): void {
        mkdir($this->dir.'/.idea');
        file_put_contents($this->dir.'/.idea/.name', "shop\n");

        $this->artisan('monocle:editor', ['--editor' => 'phpstorm', '--wsl' => 'windows-drive', '--no-interaction' => true])->assertSuccessful();

        expect(($this->settings)()['project'])->toBe('shop');

        unlink($this->dir.'/.idea/.name');
        unlink($this->dir.'/.monocle.json');

        $this->artisan('monocle:editor', ['--editor' => 'phpstorm', '--wsl' => 'windows-drive', '--map' => [$this->dir.':C:/code/store'], '--no-interaction' => true])->assertSuccessful();

        expect(($this->settings)()['project'])->toBe('store');
    });

    it('asks for the host path of a Sail project and skips WSL on macOS', function (): void {
        ($this->environment)('Darwin', '5.15.167.4-microsoft-standard-WSL2');
        mkdir($this->dir.'/vendor/bin', 0777, true);
        touch($this->dir.'/vendor/bin/sail');
        touch($this->dir.'/compose.yaml');

        $this->artisan('monocle:editor')
            ->expectsChoice('Which editor do you use?', 'custom', $this->editors)
            ->expectsQuestion('URL template', 'my://open?f={file}&l={line}')
            ->expectsQuestion("Where is {$this->dir} on the machine your editor runs on?", '/Users/me/app')
            ->expectsOutputToContain('my://open?f=/Users/me/app/artisan&l=1')
            ->assertSuccessful();

        expect(($this->settings)())->toBe(['editor' => 'my://open?f={file}&l={line}', 'paths' => [$this->dir => '/Users/me/app']]);
    });

    it('keeps other keys and drops a mapping left empty', function (): void {
        file_put_contents($this->dir.'/.monocle.json', json_encode(['editor' => 'zed', 'paths' => [$this->dir => '/old'], 'theme' => 'dark']));

        $this->artisan('monocle:editor')
            ->expectsChoice('Which editor do you use?', 'zed', $this->editors)
            ->expectsQuestion("Where is {$this->dir} on the machine your editor runs on?", '')
            ->assertSuccessful();

        expect(($this->settings)())->toEqual(['editor' => 'zed', 'paths' => [], 'theme' => 'dark']);
    });

    it('refuses invalid JSON and a missing editor', function (): void {
        $this->artisan('monocle:editor', ['--no-interaction' => true])->assertFailed();

        file_put_contents($this->dir.'/.monocle.json', '{ // mine');

        $this->artisan('monocle:editor', ['--editor' => 'zed', '--no-interaction' => true])->assertFailed();
    });

    it('writes nothing in dry-run mode', function (): void {
        $this->artisan('monocle:editor', ['--editor' => 'zed', '--wsl' => 'windows', '--distro' => 'Debian', '--dry-run' => true, '--no-interaction' => true])
            ->expectsOutputToContain('would be written')
            ->expectsOutputToContain('zed://file/\\\\wsl.localhost\\Debian')
            ->assertSuccessful();

        expect(file_exists($this->dir.'/.monocle.json'))->toBeFalse()
            ->and(file_exists($this->dir.'/.gitignore'))->toBeFalse();
    });
});
