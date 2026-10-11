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
            // Keys this command does not own are kept, so other tools can share the file.
            $otherKeys = array_diff_key($raw, array_flip(['editor', 'paths', 'wsl', 'project']));

            $this->writeJson($file, [...$otherKeys, ...$settings->toArray()]);
        }

        $this->components->twoColumnDetail(EditorSettings::FILE, $dryRun ? 'would be written' : 'written');

        $result = $gitignore->add($basePath.'/.gitignore', EditorSettings::FILE, $dryRun);
        $this->components->twoColumnDetail('.gitignore', $dryRun ? "would be {$result}" : $result);

        $example = new EditorLinks($settings, $basePath)->file('artisan', 1);
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
        $choice = (string) select('Which editor do you use?', $options, $this->defaultEditorChoice($current));

        if ($choice !== self::CUSTOM) {
            return $choice;
        }

        // Prefill the template only when the current editor is one already.
        $isTemplate = Editor::tryFrom((string) $current->editor) === null;

        return text(
            'URL template',
            placeholder: 'myeditor://open?file={file}&line={line}',
            default: $isTemplate ? (string) $current->editor : '',
            required: true,
            validate: fn (string $value): ?string => str_contains($value, '{file}') ? null : 'The template needs {file}.',
            hint: 'Placeholders: {file}, {line}, and under WSL {distro} and {linux_file}.',
        );
    }

    private function defaultEditorChoice(EditorSettings $current): string
    {
        if ($current->editor === null) {
            return Editor::PhpStorm->value;
        }

        return Editor::tryFrom($current->editor)?->value ?? self::CUSTOM;
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

        if (! Editor::tryFrom($editor)?->isJetBrains()) {
            return null;
        }

        if (! $this->editorOnWindows($location, $environment)) {
            return null;
        }

        $default = $current->project ?? $this->detectProject($basePath, $paths);

        if (! $this->input->isInteractive()) {
            return $default;
        }

        return text('Project name in your JetBrains IDE', default: $default, required: true, hint: 'Links open through JetBrains Toolbox, which finds the file in this open project.');
    }

    private function editorOnWindows(?string $location, Environment $environment): bool
    {
        if (in_array($location, ['windows', 'windows-drive'], true)) {
            return true;
        }

        return $environment->os() === 'Windows';
    }

    /**
     * The JetBrains project name: from .idea/.name, else the module file,
     * else the folder name as the editor sees it.
     *
     * @param  array<string, string>  $paths
     */
    private function detectProject(string $basePath, array $paths): string
    {
        $nameFile = $basePath.'/.idea/.name';

        if (is_file($nameFile)) {
            $name = trim((string) file_get_contents($nameFile));

            if ($name !== '') {
                return $name;
            }
        }

        $modules = $basePath.'/.idea/modules.xml';

        if (is_file($modules) && preg_match('#/\.idea/([^/"]+)\.iml"#', (string) file_get_contents($modules), $match) === 1) {
            return $match[1];
        }

        $editorPath = $paths[$basePath] ?? $basePath;

        return basename(str_replace('\\', '/', $editorPath));
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

        if (! $this->input->isInteractive()) {
            return $paths;
        }

        if ($this->option('map') !== []) {
            return $paths;
        }

        $root = $this->projectRoot($basePath);

        if (! $this->pathsMayDiffer($basePath, $location, $environment, $paths, $root)) {
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
     * Whether the editor may see the project under another path than PHP
     * does: in a container, on a Windows drive, or a mapping already exists.
     *
     * @param  array<string, string>  $paths
     */
    private function pathsMayDiffer(string $basePath, ?string $location, Environment $environment, array $paths, string $root): bool
    {
        if (in_array(Platform::detect($basePath), [Platform::Sail, Platform::Lerd], true)) {
            return true;
        }

        if ($environment->inContainer()) {
            return true;
        }

        if ($location === 'windows-drive') {
            return true;
        }

        return isset($paths[$root]);
    }

    /**
     * The folder to map: the project the command runs from, which holds the
     * application even when its base path lives deeper, as with Testbench.
     */
    private function projectRoot(string $basePath): string
    {
        $cwd = getcwd();

        if ($cwd === false) {
            return $basePath;
        }

        if (! str_starts_with($basePath, rtrim($cwd, '/').'/')) {
            return $basePath;
        }

        return $cwd;
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
