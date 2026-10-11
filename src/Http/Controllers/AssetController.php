<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the compiled dashboard assets shipped with the package, so the host
 * application needs no build step and no published files.
 *
 * @internal
 */
final class AssetController
{
    private const array FILES = [
        'monocle.css' => 'text/css; charset=utf-8',
        'monocle.js' => 'text/javascript; charset=utf-8',
        'monocle.svg' => 'image/svg+xml',
    ];

    public function __invoke(string $file): BinaryFileResponse
    {
        abort_unless(isset(self::FILES[$file]), 404);

        return response()->file(self::path($file), [
            'Content-Type' => self::FILES[$file],
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public static function version(string $file): string
    {
        return substr((string) md5_file(self::path($file)), 0, 12);
    }

    private static function path(string $file): string
    {
        return dirname(__DIR__, 3).'/resources/dist/'.$file;
    }
}
