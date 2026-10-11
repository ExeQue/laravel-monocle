<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\MediaLibrary;

use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;
use Workbench\App\Models\Gallery;

final class AddMediaController
{
    use RendersResult;

    public function __invoke(): View
    {
        $path = tempnam(sys_get_temp_dir(), 'monocle').'.png';
        $image = imagecreatetruecolor(120, 80);
        imagefill($image, 0, 0, imagecolorallocate($image, 37, 99, 235));
        imagepng($image, $path);

        $media = Gallery::query()->firstOrCreate(['name' => 'Demo gallery'])
            ->addMedia($path)
            ->usingFileName('square.png')
            ->toMediaCollection('photos');

        return $this->result('Add media', ['media' => $media->id, 'conversions' => $media->getGeneratedConversions()]);
    }
}
