<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install;

/**
 * Where this PHP process runs: the OS family and, on Linux, whether the kernel
 * is WSL's. A Sail container on WSL2 or Docker Desktop for Windows shares that
 * kernel, so the check works inside containers too.
 *
 * @internal
 */
final readonly class Environment
{
    /**
     * @param  list<string>  $kernelFiles
     * @param  array<string, string>|null  $env
     * @param  list<string>  $containerFiles
     */
    public function __construct(
        private string $os = PHP_OS_FAMILY,
        private array $kernelFiles = ['/proc/sys/kernel/osrelease', '/proc/version'],
        private ?array $env = null,
        private array $containerFiles = ['/.dockerenv', '/run/.containerenv'],
    ) {
    }

    /**
     * Running inside a Docker or Podman container, where the paths PHP sees
     * differ from the ones on the host.
     */
    public function inContainer(): bool
    {
        foreach ($this->containerFiles as $file) {
            if (file_exists($file)) {
                return true;
            }
        }

        return false;
    }

    public function os(): string
    {
        return $this->os;
    }

    public function isWsl(): bool
    {
        if ($this->os !== 'Linux') {
            return false;
        }

        foreach ($this->kernelFiles as $file) {
            if (is_readable($file) && stripos((string) file_get_contents($file), 'microsoft') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The distro name, known only in a WSL shell (not inside a container).
     *
     * ponytail: wsl.exe -l is not reachable from a container, so no lookup beyond the env.
     */
    public function wslDistro(): ?string
    {
        $distro = ($this->env ?? getenv())['WSL_DISTRO_NAME'] ?? null;

        return is_string($distro) && $distro !== '' ? $distro : null;
    }
}
