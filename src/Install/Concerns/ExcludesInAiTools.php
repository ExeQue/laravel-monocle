<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install\Concerns;

use ExeQue\Monocle\Install\GitignoreWriter;

/**
 * Lists a folder in the ignore files AI coding tools read, for the tools the
 * project uses.
 */
trait ExcludesInAiTools
{
    /**
     * Ignore file => [tool, directories that show the tool is in use].
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private array $aiIgnoreFiles = [
        '.cursorignore' => ['Cursor', ['.cursor']],
        '.codeiumignore' => ['Windsurf', ['.windsurf', '.codeium']],
        '.codexignore' => ['Codex', ['.codex']],
        '.aiignore' => ['JetBrains AI', ['.junie']],
        '.aiexclude' => ['Gemini', []],
    ];

    /**
     * @return array<string, string>
     */
    private function excludeInAiTools(string $basePath, string $relativePath, bool $dryRun): array
    {
        $writer = new GitignoreWriter();
        $results = [];

        foreach ($this->aiIgnoreFiles as $file => [$tool, $directories]) {
            if (! $this->usesAiTool($basePath, $file, $directories)) {
                continue;
            }

            $result = $writer->add($basePath.'/'.$file, $relativePath, $dryRun);
            $results["{$tool} ({$file})"] = $dryRun && $result === 'added' ? 'would be added' : $result;
        }

        return $results;
    }

    /**
     * @param  list<string>  $directories
     */
    private function usesAiTool(string $basePath, string $file, array $directories): bool
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
}
