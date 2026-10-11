<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Editor\EditorLinks;
use ExeQue\Monocle\Editor\EditorSettings;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Http\Controllers\CapturesController;
use ExeQue\Monocle\Storage\StoreManager;
use Illuminate\Support\Facades\Gate;

covers(CapturesController::class);

describe(CapturesController::class, function (): void {
    it('links the class names and paths inside a data section', function (): void {
        Gate::define('viewMonocle', fn (mixed $user = null): bool => true);
        $this->app->instance(EditorLinks::class, new EditorLinks(new EditorSettings('vscode'), '/app'));

        $capture = new Capture('c1', CaptureType::Request, microtime(true));
        $capture->endedAt = microtime(true);
        $capture->sections = ['context' => ['controller' => CapturesController::class, 'user' => ['name' => 'Ada']]];
        app(StoreManager::class)->store()->put($capture);

        $result = $this->getJson('/_monocle/api/captures/c1/rows/context')->assertOk()->json();

        expect($result['data']['user'])->toBe(['name' => 'Ada'])
            ->and(array_keys($result['links']))->toBe(['controller'])
            ->and($result['links']['controller'])->toStartWith('vscode://file/')->toContain('CapturesController.php:');
    });
});
