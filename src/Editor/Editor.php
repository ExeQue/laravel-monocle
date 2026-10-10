<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Editor;

/**
 * Editors Monocle can build "open this file" links for.
 */
enum Editor: string
{
    case PhpStorm = 'phpstorm';
    case Idea = 'idea';
    case VsCode = 'vscode';
    case Cursor = 'cursor';
    case Windsurf = 'windsurf';
    case Zed = 'zed';
    case Sublime = 'sublime';
    case Nova = 'nova';

    public function label(): string
    {
        return match ($this) {
            self::PhpStorm => 'PhpStorm',
            self::Idea => 'IntelliJ IDEA',
            self::VsCode => 'VS Code',
            self::Cursor => 'Cursor',
            self::Windsurf => 'Windsurf',
            self::Zed => 'Zed',
            self::Sublime => 'Sublime Text',
            self::Nova => 'Nova',
        };
    }

    /**
     * Link that opens the file at the line. $file is the absolute path on the
     * machine the editor runs on, with forward slashes; with a WSL distro the
     * editor runs on Windows and the file lives inside that distro.
     */
    public function link(string $file, int $line, ?string $wslDistro = null): string
    {
        if ($wslDistro !== null && $this->isVsCodeFamily()) {
            return sprintf('%s://vscode-remote/wsl+%s/%s:%d', $this->value, rawurlencode($wslDistro), ltrim($file, '/'), $line);
        }

        $path = $wslDistro === null ? $file : self::wslPath($file, $wslDistro);

        return match ($this) {
            self::PhpStorm, self::Idea => sprintf('%s://open?file=%s&line=%d', $this->value, rawurlencode($path), $line),
            self::VsCode, self::Cursor, self::Windsurf, self::Zed => sprintf('%s://file/%s:%d', $this->value, ltrim($path, '/'), $line),
            self::Sublime => sprintf('subl://open?url=%s&line=%d', rawurlencode('file://'.$path), $line),
            self::Nova => sprintf('nova://open?path=%s&line=%d', rawurlencode($path), $line),
        };
    }

    /**
     * The Windows path of a file inside a WSL distro.
     */
    public static function wslPath(string $file, string $distro): string
    {
        return '\\\\wsl.localhost\\'.$distro.str_replace('/', '\\', '/'.ltrim($file, '/'));
    }

    private function isVsCodeFamily(): bool
    {
        return in_array($this, [self::VsCode, self::Cursor, self::Windsurf], true);
    }
}
