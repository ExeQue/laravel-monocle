<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Support;

use Illuminate\Support\Str;

/**
 * The headers and environment variables that carry a trace from a capture
 * to the captures started from it in the browser, in other processes and
 * in other applications.
 *
 * @internal
 */
final class TraceContext
{
    public const string PARENT_HEADER = 'X-Monocle-Parent';

    /** The dashboard url of the application that sent a call. */
    public const string ORIGIN_HEADER = 'X-Monocle-Origin';

    /** The name an application gives itself (trace.name), sent both ways. */
    public const string NAME_HEADER = 'X-Monocle-Name';

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

    /**
     * A dashboard url from outside when it is an absolute http(s) url, without
     * its fragment: it ends up in a link, so a javascript: url never gets through.
     */
    public static function dashboardUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = Str::before($value, '#');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true) || (string) parse_url($url, PHP_URL_HOST) === '') {
            return null;
        }

        return $url;
    }

    /**
     * The window a dashboard opens in, named after its host (and a port
     * other than the scheme's default), so links to one application land in
     * the same tab. The dashboard's own tab takes the same name, which is
     * why these links leave noopener out: a named tab is only found again
     * from the tab that opened it, or from one it opened.
     */
    public static function windowName(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = parse_url($url, PHP_URL_PORT);
        $default = $scheme === 'http' ? 80 : 443;

        return 'monocle:'.strtolower((string) parse_url($url, PHP_URL_HOST)).($port === null || $port === $default ? '' : ':'.$port);
    }

    /**
     * An application name from outside or from config, trimmed and cut to
     * 100 characters; null when there is none.
     */
    public static function name(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $name = trim(Str::limit(trim($value), 100, ''));

        return $name === '' ? null : $name;
    }
}
