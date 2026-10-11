<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Dashboard;

/**
 * Durations, sizes and values written the way the dashboard writes them,
 * for views rendered on the server.
 *
 * @internal
 */
final class Format
{
    public static function ms(mixed $ms): string
    {
        $value = (float) ($ms ?? 0);

        if ($value > 0 && $value < 0.05) {
            return '<0.1 ms';
        }

        if ($value >= 1000) {
            return number_format($value / 1000, 2, '.', '').' s';
        }

        return number_format($value, 1, '.', '').' ms';
    }

    public static function bytes(mixed $bytes): string
    {
        $value = (int) $bytes;

        return match (true) {
            $value >= 1048576 => number_format($value / 1048576, 1, '.', '').' MB',
            $value >= 1024 => number_format($value / 1024, 1, '.', '').' KB',
            default => $value.' B',
        };
    }

    /**
     * A value as one line of text: lists of scalars joined, the rest as JSON.
     */
    public static function value(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (self::isScalarList($value)) {
            return implode(', ', $value);
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @phpstan-assert-if-true list<scalar> $value
     */
    private static function isScalarList(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if ($value === []) {
            return false;
        }

        if (! array_is_list($value)) {
            return false;
        }

        return array_filter($value, is_scalar(...)) === $value;
    }

    /**
     * Nested data as dot.notated [key, value] pairs, like the request tab shows input.
     *
     * @return list<array{0: string, 1: mixed}>
     */
    public static function dot(mixed $value, string $prefix = ''): array
    {
        if (! is_array($value)) {
            return $prefix === '' ? [] : [[$prefix, $value]];
        }

        if ($value === []) {
            return $prefix === '' ? [] : [[$prefix, '[]']];
        }

        $pairs = [];

        foreach ($value as $key => $item) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $pairs = [...$pairs, ...self::dot($item, $path)];
        }

        return $pairs;
    }

    /**
     * HTTP headers ([name => list of values]) as [name, value] pairs.
     *
     * @param  array<string, mixed>|null  $headers
     * @return list<array{0: string, 1: string}>
     */
    public static function headers(?array $headers): array
    {
        $pairs = [];

        foreach ($headers ?? [] as $name => $values) {
            $value = is_array($values)
                ? implode(', ', array_map(self::value(...), $values))
                : self::value($values);

            $pairs[] = [(string) $name, $value];
        }

        return $pairs;
    }
}
