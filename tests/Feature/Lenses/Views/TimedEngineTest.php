<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Views;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Views\TimedEngine;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Engines\FileEngine;

covers(TimedEngine::class);

describe(TimedEngine::class, function (): void {
    beforeEach(function (): void {
        $this->file = sys_get_temp_dir().'/monocle-engine-'.bin2hex(random_bytes(4)).'.html';
        file_put_contents($this->file, '<p>static</p>');

        $this->engine = new TimedEngine(new FileEngine(new Filesystem()), 'file', app(Eyepiece::class), fn (): array => ['/views'], '/base');
    });

    afterEach(function (): void {
        @unlink($this->file);
    });

    it('names a view outside the view paths after its file', function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);

        $html = $this->engine->get($this->file);
        $row = app(Eyepiece::class)->finalize()->rows('views')[0];

        expect($html)->toBe('<p>static</p>')
            ->and($row)->toMatchArray(['view' => basename($this->file, '.html'), 'path' => $this->file, 'engine' => 'file']);
    });

    it('renders without recording outside a capture', function (): void {
        expect($this->engine->get($this->file))->toBe('<p>static</p>')
            ->and(app(Eyepiece::class)->active())->toBeNull();
    });
});
