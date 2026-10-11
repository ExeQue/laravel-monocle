<?php

declare(strict_types=1);

use ExeQue\Monocle\Install\ConfigPublisher;

covers(ConfigPublisher::class);

/**
 * Load a rendered config the way Laravel would: include a real file.
 *
 * @return array<string, mixed>
 */
function loadRenderedConfig(string $rendered): array
{
    $file = tempnam(sys_get_temp_dir(), 'monocle-config').'.php';
    file_put_contents($file, $rendered);

    try {
        return include $file;
    } finally {
        unlink($file);
    }
}

describe(ConfigPublisher::class, function (): void {
    beforeEach(function (): void {
        $this->publisher = new ConfigPublisher(dirname(__DIR__, 3).'/config/monocle.php');
        $this->target = sys_get_temp_dir().'/monocle-publish-'.bin2hex(random_bytes(4)).'/config/monocle.php';
    });

    afterEach(function (): void {
        exec('rm -rf '.escapeshellarg(dirname($this->target, 2)));
    });

    it('writes the storage driver and limit, keeping comments', function (): void {
        $rendered = $this->publisher->render(['driver' => 'cache', 'limit' => 50]);
        $config = loadRenderedConfig($rendered);

        expect($rendered)->toContain("env('MONOCLE_STORAGE', 'cache')")
            ->and($rendered)->toContain('| Lenses')
            ->and($config['storage']['limit'])->toBe(50);
    });

    it('publishes, reports updates and leaves identical files alone', function (): void {
        expect($this->publisher->publish($this->target, ['limit' => 10]))->toBe('created')
            ->and($this->publisher->publish($this->target, ['limit' => 10]))->toBe('unchanged')
            ->and($this->publisher->publish($this->target, ['limit' => 11], dryRun: true))->toBe('updated')
            ->and(file_get_contents($this->target))->toContain("'limit' => 10,")
            ->and($this->publisher->publish($this->target, ['limit' => 11]))->toBe('updated')
            ->and(file_get_contents($this->target))->toContain("'limit' => 11,");
    });
});
