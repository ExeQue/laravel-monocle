<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Mcp\Tools\Concerns;

use Laravel\Mcp\Response;

/** @internal */
trait RespondsWithJson
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function json(array $data): Response
    {
        if (isset($data['error'])) {
            return Response::error((string) $data['error']);
        }

        return Response::json($data);
    }
}
