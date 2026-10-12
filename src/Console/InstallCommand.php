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
            $this->components->twoColumnDetail('config/monocle.php', $this->outcome($result, $dryRun));
        }

        foreach ($choices['files'] as $file) {
            if ($file === self::BOOST) {
                foreach ($boost->install($server, $command, $dryRun) as $agent => $result) {
                    $this->components->twoColumnDetail("Boost: {$agent}", $result);
                }

                continue;
            }

            $result = $mcp->write($this->absolutePath($basePath, $file), $server, $command, $dryRun);
            $this->components->twoColumnDetail($file, $this->outcome($result, $dryRun));
        }

        $this->ignoreFiles($gitignore, $ide, $basePath, $choices['driver'] === 'file', $dryRun);

        foreach ($skill->install($basePath, $choices['skill'], $dryRun) as $file => $result) {
            $this->components->twoColumnDetail($file, $this->outcome($result, $dryRun));
        }

        $this->newLine();
        $this->components->bulletList($this->nextSteps($boost, $platform, $server, $basePath));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function nextSteps(BoostAgents $boost, Platform $platform, string $server, string $basePath): array
    {
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

        return $next;
    }

    /**
     * A --file value as an absolute path; relative ones are taken from the project root.
     */
    private function absolutePath(string $basePath, string $file): string
    {
        if (str_starts_with($file, '/')) {
            return $file;
        }

        return $basePath.'/'.preg_replace('#^\./#', '', $file);
    }

    private function outcome(string $result, bool $dryRun): string
    {
        return $dryRun ? "would be {$result}" : $result;
    }

    /**
     * @return array{driver: string, limit: int, show_host: bool, platform: string, files: list<string>, publish: bool, skill: list<string>}
     */
    private function askChoices(BoostAgents $boost, SkillInstaller $skillInstaller): array
    {
        $skills = array_map(fn (array $agent): string => "{$agent[0]} ({$agent[1]})", SkillInstaller::AGENTS);
        $targets = $this->targets($boost);
        $detected = Platform::detect($this->laravel->basePath());
        $platforms = collect(Platform::cases())
            ->mapWithKeys(fn (Platform $platform): array => [$platform->value => $this->platformLabel($platform)])
            ->all();

        $drivers = [
            'file' => 'Files in storage/monocle (shared by web, workers and the MCP server)',
            'cache' => 'The application cache store',
        ];

        $answers = form()
            ->intro('Monocle setup')
            ->select('Where should captures be stored?', $drivers, 'file', name: 'driver')
            ->text('How many captures should be kept?', default: '200', validate: $this->validateLimit(...), name: 'limit')
            ->confirm('Show the host of each request in the dashboard list?', false, hint: 'Useful when the application answers on more than one domain.', name: 'show_host')
            ->select('How does your MCP client start PHP?', $platforms, $this->option('platform') ?: $detected->value, name: 'platform')
            ->multiselect('Register the MCP server in', $targets, $this->option('file') ?: $this->defaultTargets($boost), required: true, name: 'files')
            ->confirm('Publish config/monocle.php with these choices?', true, name: 'publish')
            // Boost installs the skill itself with boost:update, so the question is only asked without it.
            ->addIf(! $boost->detected(), fn () => multiselect(
                'Install the Monocle skill for',
                $skills,
                $skillInstaller->detect($this->laravel->basePath()),
                scroll: 9,
                hint: 'Laravel Boost installs it for you with boost:update.',
            ), name: 'skill')
            ->submit();

        return [
            'driver' => (string) $answers['driver'],
            'limit' => (int) $answers['limit'],
            'show_host' => (bool) $answers['show_host'],
            'platform' => (string) $answers['platform'],
            'files' => array_values($answers['files']),
            'publish' => (bool) $answers['publish'],
            'skill' => array_values($answers['skill'] ?? []),
        ];
    }

    private function validateLimit(string $value): ?string
    {
        if (! ctype_digit($value)) {
            return 'Enter a positive number.';
        }

        if ((int) $value === 0) {
            return 'Enter a positive number.';
        }

        return null;
    }

    /**
     * @return array{driver: string, limit: int, show_host: bool, platform: string, files: list<string>, publish: bool, skill: list<string>}
     */
    private function choicesFromOptions(BoostAgents $boost): array
    {
        return [
            'driver' => (string) ($this->option('driver') ?: 'file'),
            'limit' => (int) ($this->option('limit') ?: 200),
            'show_host' => (bool) $this->option('show-host'),
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
        $base = rtrim($basePath, '/').'/';
        $storage = $fileStore ? $this->storageFolder($base) : null;
        $entries = [EditorSettings::FILE];

        if ($storage !== null) {
            array_unshift($entries, $storage.'/');
        }

        $result = $gitignore->add($base.'.gitignore', $entries, $dryRun);
        $this->components->twoColumnDetail('.gitignore', $this->outcome($result, $dryRun));

        if ($storage === null) {
            return;
        }

        foreach ($ide->apply($basePath, $storage, $dryRun) as $label => $outcome) {
            $this->components->twoColumnDetail($label, $outcome);
        }
    }

    /**
     * The capture folder relative to the project, or null when it lives
     * outside the project and so is nothing for git or the IDE to see.
     */
    private function storageFolder(string $base): ?string
    {
        $config = $this->laravel->make('config');
        $path = (string) $config->get('monocle.storage.file.path', $this->laravel->storagePath('monocle'));

        if (! str_starts_with($path, $base)) {
            return null;
        }

        return substr($path, strlen($base));
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
            new InputOption('show-host', null, InputOption::VALUE_NONE, 'Show the host of each request in the dashboard list'),
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be written without touching files'),
        ];
    }
}
