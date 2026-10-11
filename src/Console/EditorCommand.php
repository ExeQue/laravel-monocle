<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Editor\Editor;
use ExeQue\Monocle\Editor\EditorLinks;
use ExeQue\Monocle\Editor\EditorSettings;
use ExeQue\Monocle\Install\Concerns\WritesJson;
use ExeQue\Monocle\Install\Environment;
use ExeQue\Monocle\Install\GitignoreWriter;
use ExeQue\Monocle\Install\Platform;
use Illuminate\Console\Command;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use Symfony\Component\Console\Input\InputOption;

/**
 * Writes the developer's editor and path mappings to .monocle.json, so file
 * paths and class names in Monocle open in their editor.
 *
 * @internal
 */
final class EditorCommand extends Command
{
    use WritesJson;

    private const string CUSTOM = 'custom';

    private const array WSL_CHOICES = [
        'windows' => 'On Windows, with the project inside WSL',
        'wsl' => 'Inside WSL (WSLg, JetBrains Gateway, VS Code server)',
        'windows-drive' => 'On Windows, with the project on a Windows drive',
    ];

    protected $name = 'monocle:editor';

    protected $description = 'Choose the editor Monocle links to and map server paths to the paths your editor sees (.monocle.json)';

    public function handle(Environment $environment, GitignoreWriter $gitignore): int
    {
        $basePath = rtrim($this->laravel->basePath(), '/\\');
        $file = $basePath.'/'.EditorSettings::FILE;
        $raw = $this->readJson($file);

        if ($raw === null) {
            $this->components->error(EditorSettings::FILE.' is not valid JSON; fix or remove it first.');

            return self::FAILURE;
        }

        $current = EditorSettings::fromArray($raw);
        $editor = $this->editor($current);

        if ($editor === null) {
            $this->components->error('Pass --editor ('.implode(', ', array_column(Editor::cases(), 'value')).', or a URL template with {file} and {line}).');

            return self::FAILURE;
        }

        $location = $this->editorLocation($environment, $current);
        $distro = $location === 'windows' ? $this->distro($environment, $current) : null;
        $paths = $this->paths($basePath, $current, $location, $environment);
        $project = $this->project($basePath, $editor, $paths, $current, $location, $environment);
        $settings = new EditorSettings($editor, $paths, $distro, $project);
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun) {
            $this->writeJson($file, [...array_diff_key($raw, array_flip(['editor', 'paths', 'wsl', 'project'])), ...$settings->toArray()]);
        }

        $this->components->twoColumnDetail(EditorSettings::FILE, $dryRun ? 'would be written' : 'written');

        $result = $gitignore->add($basePath.'/.gitignore', EditorSettings::FILE, $dryRun);
        $this->components->twoColumnDetail('.gitignore', $dryRun ? "would be {$result}" : $result);

        $example = (new EditorLinks($settings, $basePath))->file('artisan', 1);
        $this->components->twoColumnDetail('Example link (artisan)', (string) $example);

        return self::SUCCESS;
    }

    private function editor(EditorSettings $current): ?string
    {
        $option = $this->option('editor');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        if (! $this->input->isInteractive()) {
            return $current->editor;
        }

        $options = [];

        foreach (Editor::cases() as $editor) {
            $options[$editor->value] = $editor->label();
        }

        $options[self::CUSTOM] = 'Other (URL template)';
        $default = $current->editor === null ? Editor::PhpStorm->value : (Editor::tryFrom($current->editor)?->value ?? self::CUSTOM);
        $choice = (string) select('Which editor do you use?', $options, $default);

        if ($choice !== self::CUSTOM) {
            return $choice;
        }

        return text(
            'URL template',
            placeholder: 'myeditor://open?file={file}&line={line}',
            default: Editor::tryFrom((string) $current->editor) === null ? (string) $current->editor : '',
            required: true,
            validate: fn (string $value): ?string => str_contains($value, '{file}') ? null : 'The template needs {file}.',
            hint: 'Placeholders: {file}, {line}, and under WSL {distro} and {linux_file}.',
        );
    }

    /**
     * Under WSL the editor may run on Windows or inside the distro.
     */
    private function editorLocation(Environment $environment, EditorSettings $current): ?string
    {
        $option = $this->option('wsl');

        if (is_string($option) && array_key_exists($option, self::WSL_CHOICES)) {
            return $option;
        }

        if (! $environment->isWsl()) {
            return null;
        }

        $default = $current->wslDistro !== null ? 'windows' : 'wsl';

        if (! $this->input->isInteractive()) {
            return $default;
        }

        return (string) select('Where does your editor run?', self::WSL_CHOICES, $default);
    }

    private function distro(Environment $environment, EditorSettings $current): ?string
    {
        $option = $this->option('distro');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        $default = $current->wslDistro ?? $environment->wslDistro();

        if (! $this->input->isInteractive()) {
            return $default;
        }

        return text('Which WSL distro holds the project?', default: (string) $default, required: true, hint: 'As listed by `wsl -l` on Windows, e.g. Ubuntu.');
    }

    /**
     * JetBrains IDEs on Windows do not register their own URL scheme; their
     * links go through JetBrains Toolbox, which needs the project name.
     *
     * @param  array<string, string>  $paths
     */
    private function project(string $basePath, string $editor, array $paths, EditorSettings $current, ?string $location, Environment $environment): ?string
    {
        $option = $this->option('project');

        if (is_string($option)) {
            return $option === '' ? null : $option;
        }

        $onWindows = in_array($location, ['windows', 'windows-drive'], true) || $environment->os() === 'Windows';

        if (! Editor::tryFrom($editor)?->isJetBrains() || ! $onWindows) {
            return null;
        }

        $default = $current->project ?? $this->detectProject($basePath, $paths);

        if (! $this->input->isInteractive()) {
            return $default;
        }

        return text('Project name in your JetBrains IDE', default: $default, required: true, hint: 'Links open through JetBrains Toolbox, which finds the file in this open project.');
    }

    /**
     * @param  array<string, string>  $paths
     */
    private function detectProject(string $basePath, array $paths): string
    {
        $name = is_file($basePath.'/.idea/.name') ? trim((string) file_get_contents($basePath.'/.idea/.name')) : '';

        if ($name === '' && is_file($basePath.'/.idea/modules.xml') && preg_match('#/\.idea/([^/"]+)\.iml"#', (string) file_get_contents($basePath.'/.idea/modules.xml'), $match) === 1) {
            $name = $match[1];
        }

        return $name !== '' ? $name : basename(str_replace('\\', '/', $paths[$basePath] ?? $basePath));
    }

    /**
     * @return array<string, string>
     */
    private function paths(string $basePath, EditorSettings $current, ?string $location, Environment $environment): array
    {
        $paths = $current->paths;

        foreach ((array) $this->option('map') as $map) {
            [$from, $to] = array_pad(explode(':', (string) $map, 2), 2, '');

            if ($from !== '' && $to !== '') {
                $paths[$from] = $to;
            }
        }

        if (! $this->input->isInteractive() || $this->option('map') !== []) {
            return $paths;
        }

        $platform = Platform::detect($basePath);
        $root = $this->projectRoot($basePath);

        if (! in_array($platform, [Platform::Sail, Platform::Lerd], true) && ! $environment->inContainer() && $location !== 'windows-drive' && ! isset($paths[$root])) {
            return $paths;
        }

        $target = text(
            "Where is {$root} on the machine your editor runs on?",
            placeholder: $location === 'windows-drive' ? 'C:/Users/you/code/project' : '/home/you/code/project',
            default: $paths[$root] ?? '',
            hint: 'Leave empty when the path is the same.',
        );

        unset($paths[$root]);

        if ($target !== '') {
            $paths[$root] = $target;
        }

        return $paths;
    }

    /**
     * The folder to map: the project the command runs from, which holds the
     * application even when its base path lives deeper, as with Testbench.
     */
    private function projectRoot(string $basePath): string
    {
        $cwd = getcwd();

        return $cwd !== false && str_starts_with($basePath, rtrim($cwd, '/').'/') ? $cwd : $basePath;
    }

    protected function getOptions(): array
    {
        return [
            new InputOption('editor', null, InputOption::VALUE_REQUIRED, 'phpstorm|idea|vscode|cursor|windsurf|zed|sublime|nova, or a URL template with {file} and {line}'),
            new InputOption('map', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Path mapping from:to, e.g. /var/www/html:/home/you/code/project (repeatable)'),
            new InputOption('wsl', null, InputOption::VALUE_REQUIRED, 'Where the editor runs under WSL: windows|wsl|windows-drive'),
            new InputOption('distro', null, InputOption::VALUE_REQUIRED, 'WSL distro holding the project, when the editor runs on Windows'),
            new InputOption('project', null, InputOption::VALUE_REQUIRED, 'JetBrains project name; links then open through JetBrains Toolbox (empty to turn off)'),
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be written without touching files'),
        ];
    }
}
