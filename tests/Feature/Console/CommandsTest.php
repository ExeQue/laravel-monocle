<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use ExeQue\Monocle\Console\ClearCommand;
use ExeQue\Monocle\Console\InstallCommand;
use ExeQue\Monocle\Console\ListCommand;
use ExeQue\Monocle\Console\QueriesCommand;
use ExeQue\Monocle\Console\RowsCommand;
use ExeQue\Monocle\Console\ShowCommand;
use ExeQue\Monocle\Console\TraceCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

covers(\ExeQue\Monocle\Install\SkillInstaller::class, ListCommand::class, ShowCommand::class, RowsCommand::class, QueriesCommand::class, TraceCommand::class, ClearCommand::class, InstallCommand::class);

function commandJson(string $command, array $parameters = []): array
{
    Artisan::call($command, $parameters + ['--compact' => true]);

    return json_decode(trim(Artisan::output()), true) ?? [];
}

describe('monocle:* commands', function (): void {
    beforeEach(function (): void {
        DB::statement('create table things (id integer primary key, name text)');
        Route::get('/things', fn () => DB::table('things')->where('id', 1)->first() ?? 'none');
        $this->get('/things')->assertOk();
    });

    it('mirror the MCP tools', function (): void {
        expect(commandJson('monocle:list', ['--uri' => 'things'])['total'])->toBe(1)
            ->and(commandJson('monocle:show', ['id' => 'latest'])['summary']['name'])->toBe('GET /things')
            ->and(commandJson('monocle:rows', ['section' => 'queries', '--where' => ['type:=:select'], '--field' => ['sql']])['rows'][0])->toHaveKey('sql')
            ->and(commandJson('monocle:queries', ['--group-by' => 'table'])['rows'][0]['key'])->toBe('things')
            ->and(commandJson('monocle:trace')['captures'])->toHaveCount(1)
            ->and(commandJson('monocle:clear'))->toBe(['cleared' => true])
            ->and(commandJson('monocle:list')['total'])->toBe(0);
    });

    it('fail with the error payload for unknown captures', function (): void {
        $this->artisan('monocle:show', ['id' => 'missing', '--compact' => true])->assertFailed();
    });
});

describe(InstallCommand::class, function (): void {
    beforeEach(function (): void {
        // The Testbench skeleton is shared by parallel workers; the installer
        // must write into a project of its own, never into vendor/.
        $this->dir = sys_get_temp_dir().'/monocle-install-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/config', 0777, true);
        $this->app->setBasePath($this->dir);
        $this->app->useConfigPath($this->dir.'/config');
    });

    afterEach(function (): void {
        exec('rm -rf '.escapeshellarg($this->dir));
    });

    it('writes the detected platform command into every given file without prompting', function (): void {
        $this->artisan('monocle:install', ['--platform' => 'lerd', '--file' => [$this->dir.'/.mcp.json', $this->dir.'/.cursor/mcp.json'], '--no-interaction' => true])
            ->expectsOutputToContain('lerd artisan mcp:start monocle')
            ->assertSuccessful();

        $written = json_decode((string) file_get_contents($this->dir.'/.cursor/mcp.json'), true);

        expect($written['mcpServers']['monocle'])->toBe(['command' => 'lerd', 'args' => ['artisan', 'mcp:start', 'monocle']])
            ->and(file_exists($this->dir.'/.mcp.json'))->toBeTrue()
            ->and(file_exists(config_path('monocle.php')))->toBeFalse();
    });

    it('publishes the config from flags when asked', function (): void {
        $this->artisan('monocle:install', ['--platform' => 'php', '--file' => [$this->dir.'/.mcp.json'], '--publish' => true, '--driver' => 'cache', '--limit' => '42', '--no-interaction' => true])
            ->expectsOutputToContain('config/monocle.php')
            ->assertSuccessful();

        $config = include config_path('monocle.php');

        expect($config['storage']['limit'])->toBe(42)
            ->and($config['storage']['driver'])->toBe('cache');
    });

    it('asks for every choice interactively and publishes the result', function (): void {
        $this->artisan('monocle:install')
            ->expectsChoice('Where should captures be stored?', 'file', ['file' => 'Files in storage/monocle (shared by web, workers and the MCP server)', 'cache' => 'The application cache store'])
            ->expectsQuestion('How many captures should be kept?', '75')
            ->expectsChoice('How does your MCP client start PHP?', 'herd', ['sail' => 'Laravel Sail (vendor/bin/sail artisan ...)', 'lerd' => 'Lerd (lerd artisan ...)', 'herd' => 'Laravel Herd (herd php artisan ...)', 'php' => 'Plain PHP (absolute php binary)'])
            ->expectsChoice('Register the MCP server in', ['.mcp.json'], ['.mcp.json' => 'Claude Code / Codex (.mcp.json)', '.cursor/mcp.json' => 'Cursor (.cursor/mcp.json)', '.vscode/mcp.json' => 'VS Code / Copilot (.vscode/mcp.json)'])
            ->expectsConfirmation('Publish config/monocle.php with these choices?', 'yes')
            ->expectsChoice('Install the Monocle skill for', ['claude', 'agents'], array_map(fn (array $agent): string => "{$agent[0]} ({$agent[1]})", \ExeQue\Monocle\Install\SkillInstaller::AGENTS))
            ->expectsOutputToContain('herd php artisan mcp:start monocle')
            ->assertSuccessful();

        $config = include config_path('monocle.php');

        $skill = (string) file_get_contents(base_path('.claude/skills/laravel-monocle/SKILL.md'));

        expect($skill)->toStartWith("---\nname: laravel-monocle")
            ->and($skill)->toContain('- `queries`: Look here first when a page is slow')
            ->and($skill)->not->toContain('@foreach')
            ->and(file_get_contents(base_path('.agents/skills/laravel-monocle/SKILL.md')))->toBe($skill)
            ->and(file_exists(base_path('.cursor/skills/laravel-monocle/SKILL.md')))->toBeFalse()
            ->and($config['storage']['limit'])->toBe(75)
            ->and($config['lenses'][\ExeQue\Monocle\Lenses\RequestLens::class])->toBe(['capture' => ['response_types' => ['application/json' => \ExeQue\Monocle\Lenses\Request\JsonCapture::class, 'application/*+json' => \ExeQue\Monocle\Lenses\Request\JsonCapture::class, 'text/html' => \ExeQue\Monocle\Lenses\Packages\Inertia\InertiaCapture::class], 'headers' => ['X-Inertia' => \ExeQue\Monocle\Lenses\Packages\Inertia\InertiaCapture::class], 'routes' => ['*livewire.update' => \ExeQue\Monocle\Lenses\Packages\Livewire\LivewireUpdateCapture::class]]])
            ->and($config['lenses'][\ExeQue\Monocle\Lenses\CacheLens::class])->not->toBeFalse()
            ->and(json_decode((string) file_get_contents($this->dir.'/.mcp.json'), true)['mcpServers']['monocle']['command'])->toBe('herd');
    });

    it('registers with every Laravel Boost agent by default when boost.json exists', function (): void {
        file_put_contents($this->dir.'/boost.json', json_encode(['agents' => ['claude_code', 'copilot', 'codex']]));

        $this->artisan('monocle:install', ['--platform' => 'sail', '--no-interaction' => true])
            ->expectsOutputToContain('Boost: claude_code')
            ->expectsOutputToContain('Boost: copilot')
            ->expectsOutputToContain('Boost: codex')
            ->assertSuccessful();

        $claude = json_decode((string) file_get_contents($this->dir.'/.mcp.json'), true);
        $vscode = json_decode((string) file_get_contents($this->dir.'/.vscode/mcp.json'), true);
        $codex = (string) file_get_contents($this->dir.'/.codex/config.toml');

        expect($claude['mcpServers']['monocle'])->toMatchArray(['command' => 'vendor/bin/sail', 'args' => ['artisan', 'mcp:start', 'monocle']])
            ->and($vscode['servers']['monocle']['command'])->toBe('vendor/bin/sail')
            ->and($codex)->toContain('[mcp_servers.monocle]')
            ->and($codex)->toContain('mcp:start');
    });

    it('offers the Boost agents first and preselects them interactively', function (): void {
        file_put_contents($this->dir.'/boost.json', json_encode(['agents' => ['claude_code']]));

        $this->artisan('monocle:install')
            ->expectsChoice('Where should captures be stored?', 'file', ['file' => 'Files in storage/monocle (shared by web, workers and the MCP server)', 'cache' => 'The application cache store'])
            ->expectsQuestion('How many captures should be kept?', '200')
            ->expectsChoice('How does your MCP client start PHP?', 'php', ['sail' => 'Laravel Sail (vendor/bin/sail artisan ...)', 'lerd' => 'Lerd (lerd artisan ...)', 'herd' => 'Laravel Herd (herd php artisan ...)', 'php' => 'Plain PHP (absolute php binary)'])
            ->expectsChoice('Register the MCP server in', ['boost'], ['boost' => 'Laravel Boost agents (claude_code)', '.mcp.json' => 'Claude Code / Codex (.mcp.json)', '.cursor/mcp.json' => 'Cursor (.cursor/mcp.json)', '.vscode/mcp.json' => 'VS Code / Copilot (.vscode/mcp.json)'])
            ->expectsConfirmation('Publish config/monocle.php with these choices?', 'no')
            ->expectsOutputToContain('Boost: claude_code')
            ->assertSuccessful();

        expect(json_decode((string) file_get_contents($this->dir.'/.mcp.json'), true)['mcpServers'])->toHaveKey('monocle');
    });

    it('excludes the storage folder in the IDEs the project uses', function (): void {
        mkdir($this->dir.'/.vscode');
        config(['monocle.storage.file.path' => $this->dir.'/storage/monocle']);

        $this->artisan('monocle:install', ['--platform' => 'php', '--file' => [$this->dir.'/.mcp.json'], '--no-interaction' => true])
            ->expectsOutputToContain('VS Code (.vscode/settings.json)')
            ->assertSuccessful();

        expect(json_decode((string) file_get_contents($this->dir.'/.vscode/settings.json'), true)['search.exclude'])->toBe(['storage/monocle/**' => true]);
    });

    it('ignores the editor settings and recommends monocle:editor until they exist', function (): void {
        config(['monocle.storage.file.path' => $this->dir.'/storage/monocle']);

        $this->artisan('monocle:install', ['--platform' => 'lerd', '--file' => [$this->dir.'/.mcp.json'], '--no-interaction' => true])
            ->expectsOutputToContain('lerd artisan monocle:editor')
            ->assertSuccessful();

        expect(file_get_contents($this->dir.'/.gitignore'))->toBe(\ExeQue\Monocle\Install\GitignoreWriter::COMMENT."\n/storage/monocle/\n/.monocle.json\n");

        file_put_contents($this->dir.'/.monocle.json', '{"editor":"zed"}');

        $this->artisan('monocle:install', ['--platform' => 'lerd', '--file' => [$this->dir.'/.mcp.json'], '--no-interaction' => true])
            ->doesntExpectOutputToContain('monocle:editor')
            ->assertSuccessful();
    });

    it('does not write in dry-run mode', function (): void {
        $file = $this->dir.'/dry.json';
        config(['monocle.storage.file.path' => $this->dir.'/storage/monocle']);

        $this->artisan('monocle:install', ['--platform' => 'php', '--file' => [$file], '--publish' => true, '--dry-run' => true, '--no-interaction' => true])
            ->expectsOutputToContain('config/monocle.php')
            ->expectsOutputToContain('dry.json')
            ->expectsOutputToContain('.gitignore')
            ->assertSuccessful();

        expect(file_exists($file))->toBeFalse()
            ->and(file_exists(config_path('monocle.php')))->toBeFalse();
    });
});
