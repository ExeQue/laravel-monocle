<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Filesystem;

use Closure;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemManager;
use League\Flysystem\FilesystemAdapter;

/**
 * Wraps the Flysystem adapter of every disk the lens selected so its
 * operations are recorded. Other disks are untouched.
 */
final class MonocleFilesystemManager extends FilesystemManager
{
    private ?string $resolving = null;

    /**
     * @param  Closure(string): bool  $shouldRecord
     */
    public function __construct(
        Application $app,
        private readonly Eyepiece $eyepiece,
        private readonly Closure $shouldRecord
    ) {
        parent::__construct($app);
    }

    protected function resolve($name, $config = null)
    {
        $previous = $this->resolving;
        $this->resolving = (string) $name;

        try {
            return parent::resolve($name, $config);
        } finally {
            $this->resolving = $previous;
        }
    }

    protected function createFlysystem(FilesystemAdapter $adapter, array $config)
    {
        $disk = $this->resolving ?? (string) ($config['name'] ?? 'disk');

        if (($this->shouldRecord)($disk) && ! $adapter instanceof RecordingAdapter) {
            $adapter = new RecordingAdapter(
                inner: $adapter,
                disk: $disk,
                driver: isset($config['driver']) ? (string) $config['driver'] : null,
                eyepiece: $this->eyepiece,
            );
        }

        return parent::createFlysystem($adapter, $config);
    }
}
