<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use ExeQue\Monocle\Install\SkillInstaller;
use RuntimeException;

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

    it('fails when the skill template cannot be read', function (): void {
        set_error_handler(fn (): bool => true);

        try {
            expect(fn () => new SkillInstaller('/nowhere/SKILL.blade.php')->render())->toThrow(RuntimeException::class, 'Could not read');
        } finally {
            restore_error_handler();
        }
    });

    it('fails when a skills folder cannot be created', function (): void {
        touch($this->dir.'/.claude');
        set_error_handler(fn (): bool => true);

        try {
            expect(fn () => $this->installer->install($this->dir, ['claude']))->toThrow(RuntimeException::class, 'Could not create');
        } finally {
            restore_error_handler();
        }
    });

    it('rejects an unknown agent', function (): void {
        expect(fn () => $this->installer->install($this->dir, ['emacs']))->toThrow(RuntimeException::class, 'Unknown agent [emacs].');
    });
});
