<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install;

use RuntimeException;

/**
 * Publishes config/monocle.php with the choices made during install, by
 * editing the shipped config text so every comment survives.
 *
 * @phpstan-type Choices array{lenses?: list<class-string>, disks?: list<string>, driver?: string, limit?: int}
 */
final class ConfigPublisher
{
    public function __construct(private readonly string $source)
    {
    }

    /**
     * @param  Choices  $choices
     * @return 'created'|'updated'|'unchanged'
     */
    public function publish(string $target, array $choices, bool $dryRun = false): string
    {
        $rendered = $this->render($choices);
        $exists = is_file($target);

        if ($exists && file_get_contents($target) === $rendered) {
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

        if (array_key_exists('lenses', $choices)) {
            $source = $this->applyLenses($source, $choices['lenses']);
        }

        if (array_key_exists('disks', $choices)) {
            $source = $this->replaceOnce($source, "/'disks' => \[[^\]]*\],/", "'disks' => ".$this->exportList($choices['disks']).',');
        }

        if (array_key_exists('driver', $choices)) {
            $source = $this->replaceOnce($source, "/env\('MONOCLE_STORAGE', '[a-z]+'\)/", "env('MONOCLE_STORAGE', '".$choices['driver']."')");
        }

        if (array_key_exists('limit', $choices)) {
            $source = $this->replaceOnce($source, "/'limit' => \d+,/", "'limit' => ".(int) $choices['limit'].',');
        }

        return $source;
    }

    /**
     * Every lens class listed in the shipped config, in order.
     *
     * @return list<class-string>
     */
    public function lensesIn(): array
    {
        $source = (string) file_get_contents($this->source);
        preg_match_all('/^use (ExeQue\\\\Monocle\\\\Lenses\\\\\w+Lens);$/m', $source, $matches);

        return $matches[1];
    }

    /**
     * Lenses not chosen are set to false; the entry keeps its position so the
     * options stay documented in the file.
     *
     * @param  list<class-string>  $enabled
     */
    private function applyLenses(string $source, array $enabled): string
    {
        foreach ($this->lensesIn() as $class) {
            if (in_array($class, $enabled, true)) {
                continue;
            }

            $short = substr($class, (int) strrpos($class, '\\') + 1);
            $pattern = '/^( {8}'.preg_quote($short, '/').'::class => )(?:true,|\[\n(?:.*\n)*? {8}\],)$/m';
            $source = preg_replace($pattern, '${1}false,', $source, 1) ?? $source;
        }

        return $source;
    }

    private function replaceOnce(string $source, string $pattern, string $replacement): string
    {
        return preg_replace($pattern, $replacement, $source, 1) ?? $source;
    }

    /**
     * @param  list<string>  $values
     */
    private function exportList(array $values): string
    {
        if ($values === []) {
            return '[]';
        }

        return '['.implode(', ', array_map(fn (string $value): string => var_export($value, true), $values)).']';
    }
}
