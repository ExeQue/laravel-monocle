<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Filesystem;

use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class PutGetDeleteController
{
    use RendersResult;

    public function __invoke(): View
    {
        $disk = Storage::disk('demo');
        $disk->put('notes/hello.txt', 'Hello from the demo.');
        $contents = $disk->get('notes/hello.txt');
        $exists = $disk->exists('notes/missing.txt');
        $disk->delete('notes/hello.txt');

        return $this->result('Filesystem', compact('contents', 'exists'));
    }
}
