<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Editor;

/**
 * The developer's own editor settings, read from .monocle.json in the project
 * root.
 */
final readonly class EditorSettings
{
    public const string FILE = '.monocle.json';

    /**
     * @param  string|null  $editor  an Editor value, or a URL template with {file} and {line}
     * @param  array<string, string>  $paths  path on the server => path on the machine the editor runs on
     * @param  string|null  $wslDistro  set when the editor runs on Windows and the project lives in this WSL distro
     * @param  string|null  $project  JetBrains project name; links then go through JetBrains Toolbox
     */
    public function __construct(
        public ?string $editor = null,
        public array $paths = [],
        public ?string $wslDistro = null,
        public ?string $project = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $paths = [];

        foreach ((array) ($data['paths'] ?? []) as $from => $to) {
            if (is_string($to) && $to !== '') {
                $paths[(string) $from] = $to;
            }
        }

        $editor = $data['editor'] ?? null;
        $distro = $data['wsl']['distro'] ?? null;
        $project = $data['project'] ?? null;

        return new self(
            editor: is_string($editor) && $editor !== '' ? $editor : null,
            paths: $paths,
            wslDistro: is_string($distro) && $distro !== '' ? $distro : null,
            project: is_string($project) && $project !== '' ? $project : null,
        );
    }

    /**
     * Null when the file is missing or is not valid JSON.
     */
    public static function fromFile(string $file): ?self
    {
        if (! is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? self::fromArray($data) : null;
    }

    /**
     * @return array{editor: string|null, paths: object, wsl?: array{distro: string}, project?: string}
     */
    public function toArray(): array
    {
        return array_filter([
            'editor' => $this->editor,
            'paths' => (object) $this->paths,
            'wsl' => $this->wslDistro === null ? null : ['distro' => $this->wslDistro],
            'project' => $this->project,
        ], fn (mixed $value): bool => $value !== null);
    }
}
