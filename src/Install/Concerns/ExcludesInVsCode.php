<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install\Concerns;

/**
 * Leaves a folder out of search and file watching in VS Code and its forks
 * (Cursor, Windsurf), which read the same settings file.
 */
trait ExcludesInVsCode
{
    use WritesJson;

    /**
     * @return array<string, string>
     */
    private function excludeInVsCode(string $basePath, string $relativePath, bool $dryRun): array
    {
        if (! is_dir($basePath.'/.vscode')) {
            return [];
        }

        $label = 'VS Code (.vscode/settings.json)';
        $file = $basePath.'/.vscode/settings.json';
        $existed = is_file($file);
        $settings = $this->readJson($file);

        if ($settings === null) {
            return [$label => "skipped: settings.json has comments - add \"{$relativePath}/**\" to search.exclude yourself"];
        }

        $search = $relativePath.'/**';
        $watcher = '**/'.$relativePath.'/**';

        if (($settings['search.exclude'][$search] ?? null) === true && ($settings['files.watcherExclude'][$watcher] ?? null) === true) {
            return [$label => 'unchanged'];
        }

        $settings['search.exclude'] = [...($settings['search.exclude'] ?? []), $search => true];
        $settings['files.watcherExclude'] = [...($settings['files.watcherExclude'] ?? []), $watcher => true];

        if ($dryRun) {
            return [$label => $existed ? 'would be updated' : 'would be created'];
        }

        $this->writeJson($file, $settings);

        return [$label => $existed ? 'updated' : 'created'];
    }
}
