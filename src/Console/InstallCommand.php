<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Editor\EditorSettings;
use ExeQue\Monocle\Install\BoostAgents;
use ExeQue\Monocle\Install\ConfigPublisher;
use ExeQue\Monocle\Install\GitignoreWriter;
use ExeQue\Monocle\Install\IdeExclusions;
use ExeQue\Monocle\Install\McpConfigWriter;
use ExeQue\Monocle\Install\Platform;
use ExeQue\Monocle\Install\SkillInstaller;
use Illuminate\Console\Command;

use function Laravel\Prompts\form;
use function Laravel\Prompts\multiselect;

use Symfony\Component\Console\Input\InputOption;

/**
 * Interactive install: pick storage and MCP clients, publish a config that
 * reflects the choices and register the MCP server. Every answer
 * has a flag, so --no-interaction works for scripts.
 *
 * @internal
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

    protected $description = 'Set up Monocle: choose storage, publish config/monocle.php and register the MCP server (Sail, Lerd, Herd or plain PHP detected)';

    public function handle(McpConfigWriter $mcp, GitignoreWriter $gitignore, ConfigPublisher $publisher, BoostAgents $boost, IdeExclusions $ide, SkillInstaller $skill): int
    {
        $basePath = $this->laravel->basePath();
        $choices = $this->input->isInteractive() ? $this->askChoices($boost, $skill) : $this->choicesFromOptions($boost);

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

        $this->ignoreFiles($gitignore, $ide, $basePath, $choices['driver'] === 'file', $dryRun);

        foreach ($skill->install($basePath, $choices['skill'], $dryRun) as $file => $result) {
            $this->components->twoColumnDetail($file, $dryRun ? "would be {$result}" : $result);
        }

        $next = [
            'Restart your MCP client so it picks up the new server.',
            "Claude Code: add \"{$server}\" to enabledMcpjsonServers in .claude/settings.local.json if you use that allow-list.",
        ];

        if ($boost->detected()) {
            $next[] = "Run `{$platform->artisan()} boost:update` to give your agents the Monocle skill.";
        }

        if (! is_file($basePath.'/'.EditorSettings::FILE)) {
            $next[] = "Run `{$platform->artisan()} monocle:editor` to open file paths from Monocle in your editor.";
        }

        $this->newLine();
        $this->components->bulletList($next);

        return self::SUCCESS;
    }

    /**
     * @return array{driver: string, limit: int, platform: string, files: list<string>, publish: bool, skill: list<string>}
     */
    private function askChoices(BoostAgents $boost, SkillInstaller $skillInstaller): array
    {
        $skills = array_map(fn (array $agent): string => "{$agent[0]} ({$agent[1]})", SkillInstaller::AGENTS);
        $targets = $this->targets($boost);
        $detected = Platform::detect($this->laravel->basePath());
        $platforms = [];

        foreach (Platform::cases() as $platform) {
            $platforms[$platform->value] = $this->platformLabel($platform);
        }

        $answers = form()
            ->intro('Monocle setup')
            ->select('Where should captures be stored?', ['file' => 'Files in storage/monocle (shared by web, workers and the MCP server)', 'cache' => 'The application cache store'], 'file', name: 'driver')
            ->text('How many captures should be kept?', default: '200', validate: fn (string $value): ?string => ctype_digit($value) && (int) $value > 0 ? null : 'Enter a positive number.', name: 'limit')
            ->select('How does your MCP client start PHP?', $platforms, $this->option('platform') ?: $detected->value, name: 'platform')
            ->multiselect('Register the MCP server in', $targets, $this->option('file') ?: $this->defaultTargets($boost), required: true, name: 'files')
            ->confirm('Publish config/monocle.php with these choices?', true, name: 'publish')
            ->addIf(! $boost->detected(), fn () => multiselect('Install the Monocle skill for', $skills, $skillInstaller->detect($this->laravel->basePath()), scroll: 9, hint: 'Laravel Boost installs it for you with boost:update.'), name: 'skill')
            ->submit();

        return [
            'driver' => (string) $answers['driver'],
            'limit' => (int) $answers['limit'],
            'platform' => (string) $answers['platform'],
            'files' => array_values($answers['files']),
            'publish' => (bool) $answers['publish'],
            'skill' => array_values($answers['skill'] ?? []),
        ];
    }

    /**
     * @return array{driver: string, limit: int, platform: string, files: list<string>, publish: bool, skill: list<string>}
     */
    private function choicesFromOptions(BoostAgents $boost): array
    {
        return [            'driver' => (string) ($this->option('driver') ?: 'file'),
            'limit' => (int) ($this->option('limit') ?: 200),
            'platform' => (string) ($this->option('platform') ?: Platform::detect($this->laravel->basePath())->value),
            'files' => array_values((array) $this->option('file')) ?: $this->defaultTargets($boost),
            'publish' => (bool) $this->option('publish'),
            'skill' => array_values((array) $this->option('skill')),
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
     * Keep the personal editor settings and, with the file store, the
     * captures out of version control; keep the captures out of the IDE's
     * index too.
     */
    private function ignoreFiles(GitignoreWriter $gitignore, IdeExclusions $ide, string $basePath, bool $fileStore, bool $dryRun): void
    {
        $config = $this->laravel->make('config');
        $path = (string) $config->get('monocle.storage.file.path', $this->laravel->storagePath('monocle'));
        $base = rtrim($basePath, '/').'/';
        $storage = $fileStore && str_starts_with($path, $base) ? substr($path, strlen($base)) : null;

        $result = $gitignore->add($base.'.gitignore', array_filter([$storage === null ? null : $storage.'/', EditorSettings::FILE]), $dryRun);
        $this->components->twoColumnDetail('.gitignore', $dryRun ? "would be {$result}" : $result);

        if ($storage === null) {
            return;
        }

        foreach ($ide->apply($basePath, $storage, $dryRun) as $label => $outcome) {
            $this->components->twoColumnDetail($label, $outcome);
        }
    }

    protected function getOptions(): array
    {
        return [
            new InputOption('platform', null, InputOption::VALUE_REQUIRED, 'Override detection: sail|lerd|herd|php'),
            new InputOption('file', 'f', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Where to register the MCP server: "boost" for every Laravel Boost agent, or a config file relative to the project root. Default: boost when boost.json exists, otherwise .mcp.json'),
            new InputOption('publish', null, InputOption::VALUE_NONE, 'Publish config/monocle.php (non-interactive runs skip it unless this is set)'),
            new InputOption('skill', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Install the Monocle skill for an agent (repeatable): '.implode('|', array_keys(SkillInstaller::AGENTS))),
            new InputOption('driver', null, InputOption::VALUE_REQUIRED, 'Storage driver for captures: file|cache'),
            new InputOption('limit', null, InputOption::VALUE_REQUIRED, 'How many captures to keep'),
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be written without touching files'),
        ];
    }
}
