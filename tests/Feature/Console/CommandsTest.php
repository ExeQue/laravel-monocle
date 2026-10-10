<?php

declare(strict_types=1);

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

covers(ListCommand::class, ShowCommand::class, RowsCommand::class, QueriesCommand::class, TraceCommand::class, ClearCommand::class, InstallCommand::class);

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
            ->and(commandJson('monocle:trace')['entries'])->toHaveCount(1)
            ->and(commandJson('monocle:clear'))->toBe(['cleared' => true])
            ->and(commandJson('monocle:list')['total'])->toBe(0);
    });

    it('fail with the error payload for unknown entries', function (): void {
        $this->artisan('monocle:show', ['id' => 'missing', '--compact' => true])->assertFailed();
    });
});

describe(InstallCommand::class, function (): void {
    it('writes the detected platform command into every given file', function (): void {
        $dir = sys_get_temp_dir() . '/monocle-install-' . bin2hex(random_bytes(4));
        mkdir($dir);

        $this->artisan('monocle:install', ['--platform' => 'lerd', '--file' => [$dir . '/.mcp.json', $dir . '/.ai/mcp/mcp.json']])
            ->expectsOutputToContain('lerd php artisan mcp:start monocle')
            ->assertSuccessful();

        $written = json_decode((string) file_get_contents($dir . '/.ai/mcp/mcp.json'), true);

        expect($written['mcpServers']['monocle'])->toBe(['command' => 'lerd', 'args' => ['php', 'artisan', 'mcp:start', 'monocle']])
            ->and(file_exists($dir . '/.mcp.json'))->toBeTrue();

        exec('rm -rf ' . escapeshellarg($dir));
    });

    it('does not write in dry-run mode', function (): void {
        $file = sys_get_temp_dir() . '/monocle-install-' . bin2hex(random_bytes(4)) . '.json';
        config(['monocle.storage.file.path' => base_path('storage/monocle')]);

        $this->artisan('monocle:install', ['--platform' => 'php', '--file' => [$file], '--dry-run' => true])
            ->expectsOutputToContain('would be created')
            ->expectsOutputToContain('.gitignore')
            ->assertSuccessful();

        expect(file_exists($file))->toBeFalse();
    });
});
