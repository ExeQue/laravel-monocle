<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Exceptions;

use Illuminate\Http\Response;

final class ServerErrorResponseController
{
    public function __invoke(): Response
    {
        return response('The upstream service is unavailable.', 503);
    }
}
