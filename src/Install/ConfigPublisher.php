<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install;

use RuntimeException;

/**
 * Publishes config/monocle.php with the choices made during install, by
 * editing the shipped config text so every comment survives.
 *
 * @phpstan-type Choices array{driver?: string, limit?: int}
 *
 * @internal
 */
final class ConfigPublisher
{
    public function __construct(
        private readonly string $source
    ) {
    }

    /**
     * @param  Choices  $choices
     * @return 'created'|'updated'|'unchanged'
     */
    public function publish(string $target, array $choices, bool $dryRun = false): string
    {
        $rendered = $this->render($choices);
        $exists = is_file($target);
        $unchanged = $exists && file_get_contents($target) === $rendered;

        if ($unchanged) {
            return 'unchanged';
        }

        if (! $dryRun) {
            $directory = dirname($target);

            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new RuntimeException("Could not create [{$directory}].");
            }

            if (file_put_contents($target, $rendered) === false) {
                throw new RuntimeException("Could not write [{$target}].");
            }
        }

        return $exists ? 'updated' : 'created';
    }

    /**
     * @param  Choices  $choices
     */
    public function render(array $choices): string
    {
        $source = file_get_contents($this->source);

        if ($source === false) {
            throw new RuntimeException("Could not read [{$this->source}].");
        }

        if (array_key_exists('driver', $choices)) {
            $driver = "env('MONOCLE_STORAGE', '".$choices['driver']."')";
            $source = $this->replaceOnce($source, "/env\('MONOCLE_STORAGE', '[a-z]+'\)/", $driver);
        }

        if (array_key_exists('limit', $choices)) {
            $limit = "'limit' => ".(int) $choices['limit'].',';
            $source = $this->replaceOnce($source, "/'limit' => \d+,/", $limit);
        }

        return $source;
    }

    private function replaceOnce(string $source, string $pattern, string $replacement): string
    {
        return preg_replace($pattern, $replacement, $source, 1) ?? $source;
    }
}
