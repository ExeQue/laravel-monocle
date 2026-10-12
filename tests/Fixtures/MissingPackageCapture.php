<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use ExeQue\Monocle\Contracts\FormatsCapture;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Data\Card;
use ExeQue\Monocle\Data\Formatted;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A capture for a package that is not installed; it must never be used.
 */
final class MissingPackageCapture implements RequiresPackages, FormatsCapture
{
    public static function packages(): array
    {
        return ['acme/not-installed'];
    }

    public function format(Request $request, Response $response): Formatted
    {
        return Formatted::make()->card(Card::titled('never'));
    }
}
