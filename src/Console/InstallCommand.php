<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Install\GitignoreWriter;
use ExeQue\Monocle\Install\McpConfigWriter;
use ExeQue\Monocle\Install\Platform;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

final class InstallCommand extends Command
{
    protected $name = 'monocle:install';

    protected $description = 'Register the Monocle MCP server in your MCP client config (.mcp.json) with platform detection for Sail, Lerd, Herd and plain PHP';

    public function handle(McpConfigWriter $writer, GitignoreWriter $gitignore): int
    {
        $basePath = $this->laravel->basePath();
        $platform = $this->option('platform')
            ? Platform::from((string) $this->option('platform'))
            : Platform::detect($basePath);

        $server = (string) $this->laravel->make('config')->get('monocle.mcp.server', 'monocle');
        $command = $platform->mcpCommand($basePath, $server);

        $this->components->info(sprintf(
            'Platform: %s - command: %s %s',
            $platform->value,
            $command['command'],
            implode(' ', $command['args']),
        ));

        foreach ((array) $this->option('file') as $file) {
            $path = str_starts_with($file, '/') ? $file : $basePath . '/' . preg_replace('#^\./#', '', $file);
            $result = $writer->write($path, $server, $command, (bool) $this->option('dry-run'));

            $this->components->twoColumnDetail($file, $this->option('dry-run') ? "would be {$result}" : $result);
        }

        $this->ignoreStorage($gitignore, $basePath);

        $this->newLine();
        $this->components->bulletList([
            'Restart your MCP client so it picks up the new server.',
            "Claude Code: add \"{$server}\" to enabledMcpjsonServers in .claude/settings.local.json if you use that allow-list.",
        ]);

        return self::SUCCESS;
    }

    /**
     * The file store writes JSON into the project; keep it out of version control.
     */
    private function ignoreStorage(GitignoreWriter $gitignore, string $basePath): void
    {
        $config = $this->laravel->make('config');

        if ($config->get('monocle.storage.driver', 'file') !== 'file') {
            return;
        }

        $path = (string) $config->get('monocle.storage.file.path', $this->laravel->storagePath('monocle'));
        $base = rtrim($basePath, '/') . '/';

        if (! str_starts_with($path, $base)) {
            return;
        }

        $result = $gitignore->add($base . '.gitignore', substr($path, strlen($base)), (bool) $this->option('dry-run'));

        $this->components->twoColumnDetail('.gitignore', $this->option('dry-run') ? "would be {$result}" : $result);
    }

    protected function getOptions(): array
    {
        return [
            new InputOption('platform', null, InputOption::VALUE_REQUIRED, 'Override detection: sail|lerd|herd|php'),
            new InputOption('file', 'f', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'MCP config file(s) to write, relative to the project root', ['.mcp.json']),
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be written without touching files'),
        ];
    }
}
