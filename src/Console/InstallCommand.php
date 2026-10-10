<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Install\BoostAgents;
use ExeQue\Monocle\Install\ConfigPublisher;
use ExeQue\Monocle\Install\GitignoreWriter;
use ExeQue\Monocle\Install\IdeExclusions;
use ExeQue\Monocle\Install\McpConfigWriter;
use ExeQue\Monocle\Install\Platform;
use Illuminate\Console\Command;

use function Laravel\Prompts\form;

use Symfony\Component\Console\Input\InputOption;

/**
 * Interactive install: pick lenses, disks, storage and MCP clients, publish a
 * config that reflects the choices and register the MCP server. Every answer
 * has a flag, so --no-interaction works for scripts.
 */
final class InstallCommand extends Command
{
    /** Target that registers the server with every agent in boost.json. */
    public const string BOOST = 'boost';

    private const array CLIENT_FILES = [
        '.mcp.json' => 'Claude Code / Codex (.mcp.json)',
        '.cursor/mcp.json' => 'Cursor (.cursor/mcp.json)',
        '.vscode/mcp.json' => 'VS Code / Copilot (.vscode/mcp.json)',
    ];

    protected $name = 'monocle:install';

    protected $description = 'Set up Monocle: choose lenses, disks and storage, publish config/monocle.php and register the MCP server (Sail, Lerd, Herd or plain PHP detected)';

    public function handle(McpConfigWriter $mcp, GitignoreWriter $gitignore, ConfigPublisher $publisher, BoostAgents $boost, IdeExclusions $ide): int
    {
        $basePath = $this->laravel->basePath();
        $choices = $this->input->isInteractive() ? $this->askChoices($publisher, $boost) : $this->choicesFromOptions($publisher, $boost);

        $platform = Platform::from($choices['platform']);
        $server = (string) $this->laravel->make('config')->get('monocle.mcp.server', 'monocle');
        $command = $platform->mcpCommand($basePath, $server);
        $dryRun = (bool) $this->option('dry-run');

        $this->components->info(sprintf('Platform: %s - command: %s %s', $platform->value, $command['command'], implode(' ', $command['args'])));

        if ($choices['publish']) {
            $result = $publisher->publish($this->laravel->configPath('monocle.php'), $choices, $dryRun);
            $this->components->twoColumnDetail('config/monocle.php', $dryRun ? "would be {$result}" : $result);
        }

        foreach ($choices['files'] as $file) {
            if ($file === self::BOOST) {
                foreach ($boost->install($server, $command, $dryRun) as $agent => $result) {
                    $this->components->twoColumnDetail("Boost: {$agent}", $result);
                }

                continue;
            }

            $path = str_starts_with($file, '/') ? $file : $basePath.'/'.preg_replace('#^\./#', '', $file);
            $result = $mcp->write($path, $server, $command, $dryRun);
            $this->components->twoColumnDetail($file, $dryRun ? "would be {$result}" : $result);
        }

        if ($choices['driver'] === 'file') {
            $this->ignoreStorage($gitignore, $ide, $basePath, $dryRun);
        }

        $this->newLine();
        $this->components->bulletList([
            'Restart your MCP client so it picks up the new server.',
            "Claude Code: add \"{$server}\" to enabledMcpjsonServers in .claude/settings.local.json if you use that allow-list.",
        ]);

        return self::SUCCESS;
    }

    /**
     * @return array{lenses: list<class-string>, disks: list<string>, driver: string, limit: int, platform: string, files: list<string>, publish: bool}
     */
    private function askChoices(ConfigPublisher $publisher, BoostAgents $boost): array
    {
        $targets = $this->targets($boost);
        $lenses = $this->lensOptions($publisher);
        $detected = Platform::detect($this->laravel->basePath());
        $disks = array_keys((array) $this->laravel->make('config')->get('filesystems.disks', []));
        $platforms = [];

        foreach (Platform::cases() as $platform) {
            $platforms[$platform->value] = $this->platformLabel($platform);
        }

        $answers = form()
            ->intro('Monocle setup')
            ->multiselect('Which lenses should record?', $lenses, array_keys($lenses), scroll: 12, required: true, hint: 'Everything else in config/monocle.php keeps its defaults.', name: 'lenses')
            ->multiselect('Record Storage operations on which disks?', ['*' => 'All disks', ...array_combine($disks, $disks)], [], hint: 'Wildcards can be added in the config later.', name: 'disks')
            ->select('Where should captures be stored?', ['file' => 'Files in storage/monocle (shared by web, workers and the MCP server)', 'cache' => 'The application cache store'], 'file', name: 'driver')
            ->text('How many captures should be kept?', default: '200', validate: fn (string $value): ?string => ctype_digit($value) && (int) $value > 0 ? null : 'Enter a positive number.', name: 'limit')
            ->select('How does your MCP client start PHP?', $platforms, $this->option('platform') ?: $detected->value, name: 'platform')
            ->multiselect('Register the MCP server in', $targets, $this->option('file') ?: $this->defaultTargets($boost), required: true, name: 'files')
            ->confirm('Publish config/monocle.php with these choices?', true, name: 'publish')
            ->submit();

        return [
            'lenses' => array_values($answers['lenses']),
            'disks' => in_array('*', $answers['disks'], true) ? ['*'] : array_values($answers['disks']),
            'driver' => (string) $answers['driver'],
            'limit' => (int) $answers['limit'],
            'platform' => (string) $answers['platform'],
            'files' => array_values($answers['files']),
            'publish' => (bool) $answers['publish'],
        ];
    }

    /**
     * @return array{lenses: list<class-string>, disks: list<string>, driver: string, limit: int, platform: string, files: list<string>, publish: bool}
     */
    private function choicesFromOptions(ConfigPublisher $publisher, BoostAgents $boost): array
    {
        return [
            'lenses' => $publisher->lensesIn(),
            'disks' => array_values(array_filter((array) $this->option('disk'))),
            'driver' => (string) ($this->option('driver') ?: 'file'),
            'limit' => (int) ($this->option('limit') ?: 200),
            'platform' => (string) ($this->option('platform') ?: Platform::detect($this->laravel->basePath())->value),
            'files' => array_values((array) $this->option('file')) ?: $this->defaultTargets($boost),
            'publish' => (bool) $this->option('publish'),
        ];
    }

    /**
     * Where the MCP server can be registered. With Laravel Boost set up, its
     * agents come first.
     *
     * @return array<string, string>
     */
    private function targets(BoostAgents $boost): array
    {
        if (! $boost->detected()) {
            return self::CLIENT_FILES;
        }

        return [self::BOOST => 'Laravel Boost agents ('.implode(', ', $boost->agents()).')', ...self::CLIENT_FILES];
    }

    /**
     * @return list<string>
     */
    private function defaultTargets(BoostAgents $boost): array
    {
        return $boost->detected() ? [self::BOOST] : ['.mcp.json'];
    }

    /**
     * @return array<class-string, string>
     */
    private function lensOptions(ConfigPublisher $publisher): array
    {
        $options = [];

        foreach ($publisher->lensesIn() as $class) {
            /** @var Lens $lens */
            $lens = $this->laravel->make($class);
            $options[$class] = $lens->key().' - '.substr($class, (int) strrpos($class, '\\') + 1);
        }

        return $options;
    }

    private function platformLabel(Platform $platform): string
    {
        return match ($platform) {
            Platform::Sail => 'Laravel Sail (vendor/bin/sail artisan ...)',
            Platform::Lerd => 'Lerd (lerd artisan ...)',
            Platform::Herd => 'Laravel Herd (herd php artisan ...)',
            Platform::Php => 'Plain PHP (absolute php binary)',
        };
    }

    /**
     * The file store writes JSON into the project; keep it out of version
     * control and out of the IDE's index.
     */
    private function ignoreStorage(GitignoreWriter $gitignore, IdeExclusions $ide, string $basePath, bool $dryRun): void
    {
        $config = $this->laravel->make('config');
        $path = (string) $config->get('monocle.storage.file.path', $this->laravel->storagePath('monocle'));
        $base = rtrim($basePath, '/').'/';

        if (! str_starts_with($path, $base)) {
            return;
        }

        $relative = substr($path, strlen($base));
        $result = $gitignore->add($base.'.gitignore', $relative, $dryRun);

        $this->components->twoColumnDetail('.gitignore', $dryRun ? "would be {$result}" : $result);

        foreach ($ide->apply($basePath, $relative, $dryRun) as $label => $outcome) {
            $this->components->twoColumnDetail($label, $outcome);
        }
    }

    protected function getOptions(): array
    {
        return [
            new InputOption('platform', null, InputOption::VALUE_REQUIRED, 'Override detection: sail|lerd|herd|php'),
            new InputOption('file', 'f', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Where to register the MCP server: "boost" for every Laravel Boost agent, or a config file relative to the project root. Default: boost when boost.json exists, otherwise .mcp.json'),
            new InputOption('publish', null, InputOption::VALUE_NONE, 'Publish config/monocle.php (non-interactive runs skip it unless this is set)'),
            new InputOption('disk', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Disk name or wildcard to record Storage operations on (repeatable)'),
            new InputOption('driver', null, InputOption::VALUE_REQUIRED, 'Storage driver for captures: file|cache'),
            new InputOption('limit', null, InputOption::VALUE_REQUIRED, 'How many captures to keep'),
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be written without touching files'),
        ];
    }
}
