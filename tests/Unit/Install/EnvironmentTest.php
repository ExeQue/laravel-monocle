<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use ExeQue\Monocle\Install\Environment;

covers(Environment::class);

describe(Environment::class, function (): void {
    beforeEach(function (): void {
        $this->wsl = tempnam(sys_get_temp_dir(), 'monocle-kernel-');
        file_put_contents($this->wsl, "5.15.167.4-microsoft-standard-WSL2\n");
        $this->plain = tempnam(sys_get_temp_dir(), 'monocle-kernel-');
        file_put_contents($this->plain, "6.8.0-45-generic\n");
    });

    afterEach(function (): void {
        @unlink($this->wsl);
        @unlink($this->plain);
    });

    it('detects WSL from the Linux kernel', function (): void {
        expect((new Environment('Linux', [$this->wsl]))->isWsl())->toBeTrue()
            ->and((new Environment('Linux', ['/missing', $this->wsl]))->isWsl())->toBeTrue()
            ->and((new Environment('Linux', [$this->plain]))->isWsl())->toBeFalse()
            ->and((new Environment('Linux', ['/missing']))->isWsl())->toBeFalse();
    });

    it('never reports WSL on macOS or Windows', function (string $os): void {
        $environment = new Environment($os, [$this->wsl]);

        expect($environment->isWsl())->toBeFalse()
            ->and($environment->os())->toBe($os);
    })->with(['Darwin', 'Windows']);

    it('reads the distro name from the WSL shell', function (): void {
        expect((new Environment('Linux', [], ['WSL_DISTRO_NAME' => 'Ubuntu']))->wslDistro())->toBe('Ubuntu')
            ->and((new Environment('Linux', [], ['WSL_DISTRO_NAME' => '']))->wslDistro())->toBeNull()
            ->and((new Environment('Linux', [], []))->wslDistro())->toBeNull();
    });
});
