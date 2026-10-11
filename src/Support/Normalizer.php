<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Support;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use Stringable;
use UnitEnum;

/**
 * Turns arbitrary values into something JSON-safe for storage: models become
 * "Class#id", enums their value, closures and resources a placeholder.
 *
 * @internal
 */
final class Normalizer
{
    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    /**
     * @param  array<int, true>  $path  ids of the objects being normalized on the way down
     */
    public static function normalize(array $values, int $depth = 0, array $path = []): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            $out[$key] = self::value($value, $depth, $path);
        }

        return $out;
    }

    /**
     * Values nested deeper than 64 levels are cut off, and an object met again
     * inside its own array form is marked as circular.
     *
     * @param  array<int, true>  $path  ids of the objects being normalized on the way down
     */
    public static function value(mixed $value, int $depth = 0, array $path = []): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($depth >= 64) {
            return '[nested]';
        }

        if ($value instanceof Model) {
            return $value::class.'#'.$value->getKey();
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof Closure) {
            return '[closure]';
        }

        if (is_resource($value)) {
            return '[resource]';
        }

        if (is_array($value)) {
            return self::normalize($value, $depth + 1, $path);
        }

        if (is_object($value) && isset($path[spl_object_id($value)])) {
            return '[circular '.$value::class.']';
        }

        if ($value instanceof Arrayable) {
            return self::normalize($value->toArray(), $depth + 1, $path + [spl_object_id($value) => true]);
        }

        if ($value instanceof JsonSerializable) {
            $serialized = $value->jsonSerialize();

            return is_array($serialized) ? self::normalize($serialized, $depth + 1, $path + [spl_object_id($value) => true]) : $serialized;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_object($value)) {
            return '['.$value::class.']';
        }

        return $value;
    }
}
