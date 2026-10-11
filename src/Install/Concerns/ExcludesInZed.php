<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install\Concerns;

/**
 * Adds a folder to Zed's file_scan_exclusions.
 *
 * @internal
 */
trait ExcludesInZed
{
    use WritesJson;

    /**
     * Zed's own exclusions; setting the key replaces them, so they are kept.
     *
     * @var list<string>
     */
    private array $zedDefaultExclusions = [
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

    /**
     * @return array<string, string>
     */
    private function excludeInZed(string $basePath, string $relativePath, bool $dryRun): array
    {
        if (! is_dir($basePath.'/.zed')) {
            return [];
        }

        $label = 'Zed (.zed/settings.json)';
        $file = $basePath.'/.zed/settings.json';
        $existed = is_file($file);
        $settings = $this->readJson($file);

        if ($settings === null) {
            return [$label => "skipped: settings.json has comments - add \"**/{$relativePath}\" to file_scan_exclusions yourself"];
        }

        $pattern = '**/'.$relativePath;
        $exclusions = (array) ($settings['file_scan_exclusions'] ?? $this->zedDefaultExclusions);

        if (in_array($pattern, $exclusions, true) && isset($settings['file_scan_exclusions'])) {
            return [$label => 'unchanged'];
        }

        $settings['file_scan_exclusions'] = array_values(array_unique([...$exclusions, $pattern]));

        if ($dryRun) {
            return [$label => $existed ? 'would be updated' : 'would be created'];
        }

        $this->writeJson($file, $settings);

        return [$label => $existed ? 'updated' : 'created'];
    }
}
