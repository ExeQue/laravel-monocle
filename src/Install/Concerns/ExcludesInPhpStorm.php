<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install\Concerns;

use RuntimeException;

/**
 * Marks a folder as excluded in the PhpStorm project module.
 */
trait ExcludesInPhpStorm
{
    /**
     * @return array<string, string>
     */
    private function excludeInPhpStorm(string $basePath, string $relativePath, bool $dryRun): array
    {
        if (! is_dir($basePath.'/.idea')) {
            return [];
        }

        $module = $this->phpstormModule($basePath);

        if ($module === null) {
            return ['PhpStorm (.idea)' => 'skipped: no project module found'];
        }

        $label = 'PhpStorm ('.substr($module, strlen($basePath) + 1).')';
        $contents = (string) file_get_contents($module);
        $url = 'file://$MODULE_DIR$/'.$relativePath;

        if (str_contains($contents, 'url="'.$url.'"')) {
            return [$label => 'unchanged'];
        }

        $updated = $this->insertExcludeFolder($contents, $url);

        if ($updated === null) {
            return [$label => 'skipped: no project content root found'];
        }

        if (! $dryRun && file_put_contents($module, $updated) === false) {
            throw new RuntimeException("Could not write [{$module}].");
        }

        return [$label => $dryRun ? 'would be updated' : 'updated'];
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
}
