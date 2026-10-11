<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Concerns;

use ExeQue\Monocle\Console\Concerns\OutputsJson;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

covers(OutputsJson::class);

describe(OutputsJson::class, function (): void {
    beforeEach(function (): void {
        Route::get('/things', fn () => 'ok');
        $this->get('/things')->assertOk();
    });

    it('prints JSON with --agent', function (): void {
        Artisan::call('monocle:list', ['--agent' => true]);

        expect(json_decode(Artisan::output(), true)['total'])->toBe(1);
    });

    it('prints JSON for a detected agent outside the test suite', function (): void {
        $this->app['env'] = 'local';
        putenv('AI_AGENT=claude-code');

        try {
            Artisan::call('monocle:list');
            $output = Artisan::output();
        } finally {
            putenv('AI_AGENT');
            $this->app['env'] = 'testing';
        }

        expect(json_decode($output, true)['total'])->toBe(1);
    });
});
