<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use ExeQue\Monocle\Install\SkillInstaller;

covers(SkillInstaller::class);

describe(SkillInstaller::class, function (): void {
    beforeEach(function (): void {
        $this->dir = sys_get_temp_dir().'/monocle-skill-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->installer = app(SkillInstaller::class);
    });

    afterEach(function (): void {
        exec('rm -rf '.escapeshellarg($this->dir));
    });

    it('detects the agents a project uses', function (): void {
        mkdir($this->dir.'/.cursor');
        touch($this->dir.'/AGENTS.md');
        mkdir($this->dir.'/.github');

        expect($this->installer->detect($this->dir))->toBe(['cursor', 'agents']);
    });

    it('renders the skill with the enabled lenses', function (): void {
        $skill = $this->installer->render();

        expect($skill)->toStartWith("---\nname: laravel-monocle\n")
            ->and($skill)->toContain('- `cache`: ')
            ->and($skill)->toContain('monocle-lenses');
    });

    it('writes one file per skills folder, once, and respects dry-run', function (): void {
        expect($this->installer->install($this->dir, ['agents'], dryRun: true))->toBe(['.agents/skills/laravel-monocle/SKILL.md' => 'created'])
            ->and(file_exists($this->dir.'/.agents/skills/laravel-monocle/SKILL.md'))->toBeFalse()
            ->and($this->installer->install($this->dir, ['claude', 'agents']))->toBe([
                '.claude/skills/laravel-monocle/SKILL.md' => 'created',
                '.agents/skills/laravel-monocle/SKILL.md' => 'created',
            ])
            ->and($this->installer->install($this->dir, ['claude']))->toBe(['.claude/skills/laravel-monocle/SKILL.md' => 'unchanged']);
    });
});
