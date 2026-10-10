<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Editor;

use ReflectionClass;

/**
 * Turns file paths and class names into links that open them in the
 * developer's editor. Without an editor in .monocle.json there are no links.
 */
final readonly class EditorLinks
{
    public function __construct(
        private ?EditorSettings $settings,
        private string $basePath,
    ) {
    }

    public static function forProject(string $basePath): self
    {
        return new self(EditorSettings::fromFile(rtrim($basePath, '/\\').'/'.EditorSettings::FILE), $basePath);
    }

    public function enabled(): bool
    {
        return $this->settings?->editor !== null;
    }

    /**
     * @param  string  $file  absolute, or relative to the project root
     */
    public function file(string $file, ?int $line = null): ?string
    {
        if ($this->settings?->editor === null) {
            return null;
        }

        $absolute = $this->absolute($file);
        $line ??= 1;
        $editor = Editor::tryFrom($this->settings->editor);
        $relative = $this->relative($absolute);

        if ($editor?->isJetBrains() && $this->settings->project !== null && $relative !== null) {
            return $editor->toolboxLink($relative, $line, $this->settings->project);
        }

        $path = $this->map($absolute);

        if ($editor !== null) {
            return $editor->link($path, $line, $this->settings->wslDistro);
        }

        if (! str_contains($this->settings->editor, '{file}')) {
            return null;
        }

        return strtr($this->settings->editor, [
            '{file}' => $this->settings->wslDistro === null ? $path : Editor::wslPath($path, $this->settings->wslDistro),
            '{line}' => (string) $line,
            '{linux_file}' => $path,
            '{distro}' => (string) $this->settings->wslDistro,
        ]);
    }

    /**
     * Link to the file a class is declared in, when it can be autoloaded.
     */
    public function class(string $class): ?string
    {
        if (! $this->enabled() || ! class_exists($class) && ! interface_exists($class) && ! trait_exists($class) && ! enum_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName();

        return $file === false ? null : $this->file($file, $reflection->getStartLine() ?: null);
    }

    /**
     * The path inside the project, or null for files outside it.
     */
    private function relative(string $absolute): ?string
    {
        $base = rtrim(str_replace('\\', '/', $this->basePath), '/').'/';

        return str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : null;
    }

    private function absolute(string $file): string
    {
        $file = str_replace('\\', '/', $file);

        if (str_starts_with($file, '/') || preg_match('#^[A-Za-z]:/#', $file) === 1) {
            return $file;
        }

        return rtrim(str_replace('\\', '/', $this->basePath), '/').'/'.ltrim($file, '/');
    }

    /**
     * Swap the longest matching server-side prefix for the editor's path.
     */
    private function map(string $path): string
    {
        $match = null;
        $target = null;

        foreach ($this->settings->paths ?? [] as $from => $to) {
            $prefix = rtrim(str_replace('\\', '/', (string) $from), '/');

            if (($path === $prefix || str_starts_with($path, $prefix.'/')) && ($match === null || strlen($prefix) > strlen($match))) {
                $match = $prefix;
                $target = rtrim(str_replace('\\', '/', $to), '/');
            }
        }

        return $match === null ? $path : $target.substr($path, strlen($match));
    }
}
