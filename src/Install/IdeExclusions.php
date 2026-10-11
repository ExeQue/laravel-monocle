<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install;

use ExeQue\Monocle\Install\Concerns\ExcludesInAiTools;
use ExeQue\Monocle\Install\Concerns\ExcludesInPhpStorm;
use ExeQue\Monocle\Install\Concerns\ExcludesInVsCode;
use ExeQue\Monocle\Install\Concerns\ExcludesInZed;

/**
 * Keeps Monocle's storage folder out of editors and AI tools. Only touches
 * tools the project already uses.
 *
 * @internal
 */
final class IdeExclusions
{
    use ExcludesInAiTools;
    use ExcludesInPhpStorm;
    use ExcludesInVsCode;
    use ExcludesInZed;

    /**
     * @return array<string, string> tool label => result
     */
    public function apply(string $basePath, string $relativePath, bool $dryRun = false): array
    {
        $basePath = rtrim($basePath, '/');
        $relativePath = trim($relativePath, '/');

        return [
            ...$this->excludeInPhpStorm($basePath, $relativePath, $dryRun),
            ...$this->excludeInVsCode($basePath, $relativePath, $dryRun),
            ...$this->excludeInZed($basePath, $relativePath, $dryRun),
            ...$this->excludeInAiTools($basePath, $relativePath, $dryRun),
        ];
    }
}
