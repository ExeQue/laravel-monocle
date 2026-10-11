<?php

declare(strict_types=1);

namespace Tests\Fixtures\Http;

use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A response whose body cannot be read, like some streamed responses.
 */
final class UnreadableResponse extends Response
{
    public function getContent(): string|false
    {
        throw new LogicException('The content cannot be read.');
    }
}
