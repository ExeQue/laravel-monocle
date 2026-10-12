<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install;

use Illuminate\Support\Facades\Blade;
use RuntimeException;

/**
 * Writes the Monocle skill into the skill folders of the coding agents a
 * project uses, when it does not use Laravel Boost (Boost installs the skill
 * itself). The skill is rendered with the lenses this application has enabled.
 *
 * @internal
 */
final class SkillInstaller
{
    public const string NAME = 'laravel-monocle';

    /**
     * Agent => [label, skills folder, files or folders that show the project uses it].
     * The folders follow Laravel Boost's defaults.
     *
     * @var array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public const array AGENTS = [
        'claude' => ['Claude Code', '.claude/skills', ['.claude', 'CLAUDE.md']],
        'cursor' => ['Cursor', '.cursor/skills', ['.cursor']],
        'copilot' => ['GitHub Copilot', '.github/skills', ['.github/copilot-instructions.md', '.github/instructions']],
        'agents' => ['Codex, OpenCode, Amp, Zed and Antigravity', '.agents/skills', ['.agents', '.codex', 'AGENTS.md', 'opencode.json']],
        'junie' => ['Junie', '.junie/skills', ['.junie']],
        'kiro' => ['Kiro', '.kiro/skills', ['.kiro']],
        'factory' => ['Factory', '.factory/skills', ['.factory']],
        'grok' => ['Grok', '.grok/skills', ['.grok']],
        'pi' => ['Pi', '.pi/skills', ['.pi']],
    ];

    public function __construct(
        private readonly string $source
    ) {
    }

    /**
     * The agents the project appears to use.
     *
     * @return list<string>
     */
    public function detect(string $basePath): array
    {
        $base = rtrim($basePath, '/').'/';

        return collect(self::AGENTS)
            ->filter(fn (array $agent): bool => collect($agent[2])->contains(fn (string $marker): bool => file_exists($base.$marker)))
            ->keys()
            ->all();
    }

    public function render(): string
    {
        $template = file_get_contents($this->source);

        if ($template === false) {
            throw new RuntimeException("Could not read [{$this->source}].");
        }

        return rtrim(Blade::render($template)).PHP_EOL;
    }

    /**
     * @param  list<string>  $agents
     * @return array<string, 'created'|'updated'|'unchanged'> skill file => outcome
     */
    public function install(string $basePath, array $agents, bool $dryRun = false): array
    {
        $content = $this->render();
        $base = rtrim($basePath, '/').'/';
        $results = [];

        foreach ($this->folders($agents) as $folder) {
            $file = $folder.'/'.self::NAME.'/SKILL.md';
            $results[$file] = $this->write($base.$file, $content, $dryRun);
        }

        return $results;
    }

    /**
     * The skills folders of the given agents, each once: several agents share one.
     *
     * @param  list<string>  $agents
     * @return list<string>
     */
    private function folders(array $agents): array
    {
        return collect($agents)
            ->map(fn (string $agent): string => self::AGENTS[$agent][1] ?? throw new RuntimeException("Unknown agent [{$agent}]."))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return 'created'|'updated'|'unchanged'
     */
    private function write(string $target, string $content, bool $dryRun): string
    {
        $exists = is_file($target);
        $unchanged = $exists && file_get_contents($target) === $content;

        if ($unchanged) {
            return 'unchanged';
        }

        if (! $dryRun) {
            $directory = dirname($target);

            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new RuntimeException("Could not create [{$directory}].");
            }

            file_put_contents($target, $content);
        }

        return $exists ? 'updated' : 'created';
    }
}
