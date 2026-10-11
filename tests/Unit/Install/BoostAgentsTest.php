<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use ExeQue\Monocle\Install\BoostAgents;

covers(BoostAgents::class);

describe(BoostAgents::class, function (): void {
    beforeEach(function (): void {
        $this->dir = sys_get_temp_dir().'/monocle-boost-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->app->setBasePath($this->dir);
        $this->command = ['command' => 'php', 'args' => ['artisan', 'mcp:start', 'monocle']];
    });

    afterEach(function (): void {
        exec('rm -rf '.escapeshellarg($this->dir));
    });

    it('is not detected without boost.json', function (): void {
        $boost = app(BoostAgents::class);

        expect($boost->detected())->toBeFalse()
            ->and($boost->agents())->toBe([])
            ->and($boost->install('monocle', $this->command))->toBe([]);
    });

    it('reads the agents from boost.json and writes each agent its own config', function (): void {
        file_put_contents($this->dir.'/boost.json', json_encode(['agents' => ['claude_code', 'junie', 'not_an_agent']]));
        $cwd = getcwd();

        $results = app(BoostAgents::class)->install('monocle', $this->command);

        expect(app(BoostAgents::class)->agents())->toBe(['claude_code', 'junie', 'not_an_agent'])
            ->and($results['claude_code'])->toBe('.mcp.json')
            ->and($results['junie'])->toBe('.junie/mcp/mcp.json')
            ->and($results['not_an_agent'])->toBe('skipped: unknown agent')
            ->and(json_decode((string) file_get_contents($this->dir.'/.mcp.json'), true)['mcpServers']['monocle']['args'])->toBe(['artisan', 'mcp:start', 'monocle'])
            ->and(file_exists($this->dir.'/.junie/mcp/mcp.json'))->toBeTrue()
            ->and(getcwd())->toBe($cwd);
    });

    it('keeps servers that are already configured', function (): void {
        file_put_contents($this->dir.'/boost.json', json_encode(['agents' => ['claude_code']]));
        file_put_contents($this->dir.'/.mcp.json', json_encode(['mcpServers' => ['laravel-boost' => ['command' => 'php', 'args' => ['artisan', 'boost:mcp']]]]));

        app(BoostAgents::class)->install('monocle', $this->command);

        expect(array_keys(json_decode((string) file_get_contents($this->dir.'/.mcp.json'), true)['mcpServers']))->toBe(['laravel-boost', 'monocle']);
    });

    it('writes nothing in dry-run mode', function (): void {
        file_put_contents($this->dir.'/boost.json', json_encode(['agents' => ['claude_code']]));

        expect(app(BoostAgents::class)->install('monocle', $this->command, dryRun: true))->toBe(['claude_code' => 'would write .mcp.json'])
            ->and(file_exists($this->dir.'/.mcp.json'))->toBeFalse();
    });
});
