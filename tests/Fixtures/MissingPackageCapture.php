<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\ResponseCapture;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A capture for a package that is not installed; it must never be used.
 */
final class MissingPackageCapture implements RequiresPackages, ResponseCapture
{
    public static function packages(): array
    {
        return ['acme/not-installed'];
    }

    public function capture(Request $request, Response $response): ?array
    {
        return [['title' => 'never', 'items' => []]];
    }
}
