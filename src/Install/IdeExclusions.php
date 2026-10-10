<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install;

use ExeQue\Monocle\Install\Concerns\WritesJson;
use RuntimeException;

/**
 * Keeps Monocle's storage folder out of editors and AI tools: excluded in
 * PhpStorm and Zed, left out of search and file watching in VS Code, and
 * listed in the ignore files of AI coding tools. Only touches tools the
 * project already uses.
 */
final class IdeExclusions
{
    use WritesJson;

    /** Zed's own exclusions; setting the key replaces them, so they are kept. */
    private const array ZED_DEFAULT_EXCLUSIONS = [
        '**/.git',
        '**/.svn',
        '**/.hg',
        '**/.jj',
        '**/.sl',
        '**/.repo',
        '**/CVS',
        '**/.DS_Store',
        '**/Thumbs.db',
        '**/.classpath',
        '**/.settings',
    ];

    /** AI tool ignore file => directories that show the tool is in use. */
    private const array AI_IGNORE_FILES = [
        '.cursorignore' => ['Cursor', ['.cursor']],
        '.codeiumignore' => ['Windsurf', ['.windsurf', '.codeium']],
        '.aiexclude' => ['Gemini', []],
    ];

    public function __construct(private readonly GitignoreWriter $ignoreFiles = new GitignoreWriter())
    {
    }

    /**
     * @return array<string, string> IDE label => result
     */
    public function apply(string $basePath, string $relativePath, bool $dryRun = false): array
    {
        $basePath = rtrim($basePath, '/');
        $relativePath = trim($relativePath, '/');
        $results = [];

        if (is_dir($basePath.'/.idea')) {
            [$label, $result] = $this->phpstorm($basePath, $relativePath, $dryRun);
            $results[$label] = $result;
        }

        if (is_dir($basePath.'/.vscode')) {
            $results['VS Code (.vscode/settings.json)'] = $this->vscode($basePath, $relativePath, $dryRun);
        }

        if (is_dir($basePath.'/.zed')) {
            $results['Zed (.zed/settings.json)'] = $this->zed($basePath, $relativePath, $dryRun);
        }

        foreach (self::AI_IGNORE_FILES as $file => [$tool, $directories]) {
            if (! $this->uses($basePath, $file, $directories)) {
                continue;
            }

            $result = $this->ignoreFiles->add($basePath.'/'.$file, $relativePath, $dryRun);
            $results["{$tool} ({$file})"] = $dryRun && $result === 'added' ? 'would be added' : $result;
        }

        return $results;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function phpstorm(string $basePath, string $relativePath, bool $dryRun): array
    {
        $module = $this->phpstormModule($basePath);

        if ($module === null) {
            return ['PhpStorm (.idea)', 'skipped: no project module found'];
        }

        $label = 'PhpStorm ('.substr($module, strlen($basePath) + 1).')';
        $contents = (string) file_get_contents($module);
        $url = 'file://$MODULE_DIR$/'.$relativePath;

        if (str_contains($contents, 'url="'.$url.'"')) {
            return [$label, 'unchanged'];
        }

        $updated = $this->insertExcludeFolder($contents, $url);

        if ($updated === null) {
            return [$label, 'skipped: no project content root found'];
        }

        if (! $dryRun && file_put_contents($module, $updated) === false) {
            throw new RuntimeException("Could not write [{$module}].");
        }

        return [$label, $dryRun ? 'would be updated' : 'updated'];
    }

    private function phpstormModule(string $basePath): ?string
    {
        $modules = $basePath.'/.idea/modules.xml';

        if (is_file($modules) && preg_match('#filepath="\$PROJECT_DIR\$/(\.idea/[^"]+\.iml)"#', (string) file_get_contents($modules), $matches) === 1) {
            $path = $basePath.'/'.$matches[1];

            if (is_file($path)) {
                return $path;
            }
        }

        $candidates = glob($basePath.'/.idea/*.iml') ?: [];

        return $candidates[0] ?? null;
    }

    private function insertExcludeFolder(string $contents, string $url): ?string
    {
        $element = '<excludeFolder url="'.$url.'" />';

        // <content url="file://$MODULE_DIR$" /> - expand it to hold the exclusion.
        $selfClosing = '#^([ \t]*)<content url="file://\$MODULE_DIR\$"\s*/>#m';

        if (preg_match($selfClosing, $contents, $matches) === 1) {
            $indent = $matches[1];

            return (string) preg_replace(
                $selfClosing,
                $indent.'<content url="file://$MODULE_DIR$">'."\n".$indent.'  '.$element."\n".$indent.'</content>',
                $contents,
                1,
            );
        }

        if (preg_match('#^([ \t]*)<content url="file://\$MODULE_DIR\$">.*?^[ \t]*</content>#ms', $contents, $block, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        [$text, $offset] = $block[0];
        $childIndent = $block[1][0].'  ';
        $closing = strrpos($text, '</content>');
        $lineStart = strrpos(substr($text, 0, (int) $closing), "\n");
        $insertAt = $offset + ($lineStart === false ? (int) $closing : $lineStart + 1);

        return substr($contents, 0, $insertAt).$childIndent.$element."\n".substr($contents, $insertAt);
    }

    private function zed(string $basePath, string $relativePath, bool $dryRun): string
    {
        $file = $basePath.'/.zed/settings.json';
        $existed = is_file($file);
        $settings = $this->readJson($file);

        if ($settings === null) {
            return "skipped: settings.json has comments - add \"**/{$relativePath}\" to file_scan_exclusions yourself";
        }

        $pattern = '**/'.$relativePath;
        $exclusions = (array) ($settings['file_scan_exclusions'] ?? self::ZED_DEFAULT_EXCLUSIONS);

        if (in_array($pattern, $exclusions, true) && isset($settings['file_scan_exclusions'])) {
            return 'unchanged';
        }

        $settings['file_scan_exclusions'] = array_values(array_unique([...$exclusions, $pattern]));

        if ($dryRun) {
            return $existed ? 'would be updated' : 'would be created';
        }

        $this->writeJson($file, $settings);

        return $existed ? 'updated' : 'created';
    }

    /**
     * @param  list<string>  $directories
     */
    private function uses(string $basePath, string $file, array $directories): bool
    {
        if (is_file($basePath.'/'.$file)) {
            return true;
        }

        foreach ($directories as $directory) {
            if (is_dir($basePath.'/'.$directory)) {
                return true;
            }
        }

        return false;
    }

    private function vscode(string $basePath, string $relativePath, bool $dryRun): string
    {
        $file = $basePath.'/.vscode/settings.json';
        $existed = is_file($file);
        $settings = $this->readJson($file);

        if ($settings === null) {
            return "skipped: settings.json has comments - add \"{$relativePath}/**\" to search.exclude yourself";
        }

        $search = $relativePath.'/**';
        $watcher = '**/'.$relativePath.'/**';

        if (($settings['search.exclude'][$search] ?? null) === true && ($settings['files.watcherExclude'][$watcher] ?? null) === true) {
            return 'unchanged';
        }

        $settings['search.exclude'] = [...($settings['search.exclude'] ?? []), $search => true];
        $settings['files.watcherExclude'] = [...($settings['files.watcherExclude'] ?? []), $watcher => true];

        if ($dryRun) {
            return $existed ? 'would be updated' : 'would be created';
        }

        $this->writeJson($file, $settings);

        return $existed ? 'updated' : 'created';
    }
}
