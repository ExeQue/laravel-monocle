<?php

declare(strict_types=1);

use ExeQue\Monocle\Install\GitignoreWriter;
use ExeQue\Monocle\Install\McpConfigWriter;
use ExeQue\Monocle\Install\Platform;

covers(Platform::class, McpConfigWriter::class, GitignoreWriter::class);

describe(Platform::class, function (): void {
    beforeEach(function (): void {
        $this->base = sys_get_temp_dir().'/monocle-platform-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/vendor/bin', 0777, true);
    });

    afterEach(function (): void {
        exec('rm -rf '.escapeshellarg($this->base));
    });

    it('prefers lerd when the project carries a .lerd.yaml', function (): void {
        touch($this->base.'/.lerd.yaml');
        touch($this->base.'/compose.yaml');
        touch($this->base.'/vendor/bin/sail');

        expect(Platform::detect($this->base, ['HOME' => '/nowhere']))->toBe(Platform::Lerd);
    });

    it('detects sail from the binary plus a compose file', function (): void {
        touch($this->base.'/compose.yaml');
        touch($this->base.'/vendor/bin/sail');

        expect(Platform::detect($this->base, ['HOME' => '/nowhere']))->toBe(Platform::Sail);
    });

    it('detects sail from the LARAVEL_SAIL environment variable', function (): void {
        expect(Platform::detect($this->base, ['LARAVEL_SAIL' => '1', 'HOME' => '/nowhere']))->toBe(Platform::Sail);
    });

    it('detects herd from the config directory in HOME', function (): void {
        mkdir($this->base.'/home/.config/herd', 0777, true);

        expect(Platform::detect($this->base, ['HOME' => $this->base.'/home']))->toBe(Platform::Herd);
    });

    it('falls back to plain php', function (): void {
        expect(Platform::detect($this->base, ['HOME' => '/nowhere']))->toBe(Platform::Php);
    });

    it('builds the mcp command per platform', function (): void {
        expect(Platform::Lerd->mcpCommand('/app'))->toBe(['command' => 'lerd', 'args' => ['artisan', 'mcp:start', 'monocle']])
            ->and(Platform::Sail->mcpCommand('/app'))->toBe(['command' => 'vendor/bin/sail', 'args' => ['artisan', 'mcp:start', 'monocle']])
            ->and(Platform::Herd->mcpCommand('/app'))->toBe(['command' => 'herd', 'args' => ['php', 'artisan', 'mcp:start', 'monocle']])
            ->and(Platform::Php->mcpCommand('/app')['args'])->toBe(['/app/artisan', 'mcp:start', 'monocle']);
    });
});

describe(McpConfigWriter::class, function (): void {
    beforeEach(function (): void {
        $this->file = sys_get_temp_dir().'/monocle-mcp-'.bin2hex(random_bytes(4)).'/.mcp.json';
    });

    afterEach(function (): void {
        exec('rm -rf '.escapeshellarg(dirname($this->file)));
    });

    it('creates the file, merges without touching other servers and is idempotent', function (): void {
        $writer = new McpConfigWriter();
        $server = ['command' => 'herd', 'args' => ['php', 'artisan', 'mcp:start', 'monocle']];

        expect($writer->write($this->file, 'monocle', $server))->toBe('created');

        $existing = json_decode((string) file_get_contents($this->file), true);
        $existing['mcpServers']['other'] = ['command' => 'x', 'args' => []];
        $existing['custom'] = true;
        file_put_contents($this->file, json_encode($existing));

        expect($writer->write($this->file, 'monocle', $server))->toBe('unchanged');

        $server['args'][] = '--verbose';

        expect($writer->write($this->file, 'monocle', $server))->toBe('updated');

        $final = json_decode((string) file_get_contents($this->file), true);

        expect($final['mcpServers']['other']['command'])->toBe('x')
            ->and($final['custom'])->toBeTrue()
            ->and($final['mcpServers']['monocle']['args'])->toContain('--verbose');
    });

    it('keeps the indentation of an existing file', function (): void {
        mkdir(dirname($this->file));
        file_put_contents($this->file, "{\n  \"mcpServers\": {\n    \"other\": {\n      \"command\": \"x\"\n    }\n  }\n}\n");

        (new McpConfigWriter())->write($this->file, 'monocle', ['command' => 'php', 'args' => []]);

        $written = (string) file_get_contents($this->file);

        expect($written)->toContain("\n  \"mcpServers\": {\n    \"other\"")
            ->and($written)->toContain("\n    \"monocle\": {\n      \"command\"")
            ->and($written)->not->toContain('        ');
    });

    it('does not write in dry-run mode', function (): void {
        expect((new McpConfigWriter())->write($this->file, 'monocle', ['command' => 'php', 'args' => []], dryRun: true))->toBe('created')
            ->and(file_exists($this->file))->toBeFalse();
    });
});

describe(GitignoreWriter::class, function (): void {
    beforeEach(function (): void {
        $this->file = sys_get_temp_dir().'/monocle-gitignore-'.bin2hex(random_bytes(4));
    });

    afterEach(function (): void {
        @unlink($this->file);
    });

    it('appends the path once, normalizing slashes', function (): void {
        file_put_contents($this->file, "/vendor\n.env");
        $writer = new GitignoreWriter();

        expect($writer->add($this->file, 'storage/monocle'))->toBe('added')
            ->and(file_get_contents($this->file))->toBe("/vendor\n.env\n\n".GitignoreWriter::COMMENT."\n/storage/monocle/\n")
            ->and($writer->add($this->file, '/storage/monocle/'))->toBe('unchanged')
            ->and($writer->add($this->file, 'storage/monocle', dryRun: true))->toBe('unchanged');
    });

    it('creates the file when missing and respects dry-run', function (): void {
        expect((new GitignoreWriter())->add($this->file, 'storage/monocle', dryRun: true))->toBe('added')
            ->and(file_exists($this->file))->toBeFalse()
            ->and((new GitignoreWriter())->add($this->file, 'storage/monocle'))->toBe('added')
            ->and(file_get_contents($this->file))->toBe(GitignoreWriter::COMMENT."\n/storage/monocle/\n");
    });
});
