<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Support;

use Illuminate\Support\Str;

/**
 * The header and environment variables that carry a trace from a capture
 * to the captures started from it in the browser and in other processes.
 *
 * @internal
 */
final class TraceContext
{
    public const string PARENT_HEADER = 'X-Monocle-Parent';

    public const string TRACE_ENV = 'MONOCLE_TRACE_ID';

    public const string PARENT_ENV = 'MONOCLE_PARENT_ID';

    /**
     * Whether a value from outside looks like a capture id (a UUIDv7).
     *
     * @phpstan-assert-if-true string $value
     */
    public static function isCaptureId(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return Str::isUuid($value, 7);
    }

    /**
     * The parent and trace ids when both look like capture ids, null
     * otherwise: the values come from outside and are not trusted.
     *
     * @return array{parent_id: string, trace_id: string}|null
     */
    public static function link(mixed $parentId, mixed $traceId): ?array
    {
        if (! self::isCaptureId($parentId)) {
            return null;
        }

        if (! self::isCaptureId($traceId)) {
            return null;
        }

        return ['parent_id' => strtolower($parentId), 'trace_id' => strtolower($traceId)];
    }
}
