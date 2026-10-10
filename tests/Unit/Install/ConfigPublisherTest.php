<?php

declare(strict_types=1);

use ExeQue\Monocle\Install\ConfigPublisher;
use ExeQue\Monocle\Lenses\CacheLens;
use ExeQue\Monocle\Lenses\DatabaseLens;
use ExeQue\Monocle\Lenses\EventsLens;
use ExeQue\Monocle\Lenses\RequestLens;

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

    it('lists every lens in the shipped config', function (): void {
        $lenses = $this->publisher->lensesIn();

        expect($lenses)->toContain(RequestLens::class, DatabaseLens::class, CacheLens::class)
            ->and(count($lenses))->toBe(21);
    });

    it('turns unchosen lenses off whether they are booleans or option arrays, keeping comments', function (): void {
        $rendered = $this->publisher->render(['lenses' => [RequestLens::class, CacheLens::class]]);

        expect($rendered)->toContain('        RequestLens::class => true,')
            ->and($rendered)->toContain('        DatabaseLens::class => false,')
            ->and($rendered)->toContain('        EventsLens::class => false,')
            ->and($rendered)->not->toContain("'n_plus_one_threshold'")
            ->and($rendered)->toContain("        CacheLens::class => [\n            'values' => false,\n            'repeat_threshold' => 3,\n        ],")
            ->and($rendered)->toContain('| Lenses');

        $config = loadRenderedConfig($rendered);

        expect($config['lenses'][DatabaseLens::class])->toBeFalse()
            ->and($config['lenses'][EventsLens::class])->toBeFalse()
            ->and($config['lenses'][CacheLens::class])->toBe(['values' => false, 'repeat_threshold' => 3]);
    });

    it('writes disks, driver and limit', function (): void {
        $rendered = $this->publisher->render(['disks' => ['media', 'uploads-*'], 'driver' => 'cache', 'limit' => 50]);
        $config = loadRenderedConfig($rendered);

        expect($rendered)->toContain("'disks' => ['media', 'uploads-*'],")
            ->and($rendered)->toContain("env('MONOCLE_STORAGE', 'cache')")
            ->and($config['storage']['limit'])->toBe(50)
            ->and($this->publisher->render(['disks' => []]))->toContain("'disks' => [],");
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
