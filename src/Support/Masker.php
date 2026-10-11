<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Support;

use Closure;
use Illuminate\Support\Str;

/**
 * Masks secrets in headers and input before they reach storage. The actual
 * replacement is delegated to an invokable configured under `monocle.mask`.
 */
final class Masker
{
    private readonly Closure $mask;

    /** @var list<string> */
    private readonly array $patterns;

    /** @var array<string, Closure> */
    private readonly array $headers;

    /** @var array<string, Closure> */
    private readonly array $cookies;

    /** @var array<string, array<string, Closure>> class or interface => property => mask */
    private readonly array $objects;

    /**
     * Header and cookie lists map a name (wildcards allowed) to the mask used
     * for it; a plain list entry uses the default mask.
     *
     * @param  array<array-key, mixed>  $headers  header name => mask, or header name
     * @param  string|list<string>|null  $pattern  regular expressions matched against input keys
     * @param  array<array-key, mixed>  $cookies  cookie name => mask, or cookie name
     * @param  array<string, array<string, mixed>>  $objects  class or interface => property => mask (null for the default)
     */
    public function __construct(
        array $headers,
        string|array|null $pattern,
        callable $mask,
        array $cookies = [],
        array $objects = [],
    ) {
        $patterns = array_filter((array) $pattern, fn (mixed $value): bool => is_string($value) && $value !== '');

        $this->patterns = array_values($patterns);
        $this->mask = $mask(...);
        $this->headers = $this->masks($headers, lowercase: true);
        $this->cookies = $this->masks($cookies, lowercase: false);
        $this->objects = array_map(fn (array $properties): array => $this->masks($properties, lowercase: false), $objects);
    }

    /**
     * The masks for an object's properties: those listed for every class
     * and interface it is an instance of.
     *
     * @return array<string, Closure> property => mask
     */
    public function objectMasks(object $object): array
    {
        $masks = [];

        foreach ($this->objects as $class => $properties) {
            if ($object instanceof $class) {
                $masks = [...$masks, ...$properties];
            }
        }

        return $masks;
    }

    /**
     * The classes and interfaces that have properties to mask.
     *
     * @return list<string>
     */
    public function maskedClasses(): array
    {
        return array_keys($this->objects);
    }

    public function mask(mixed $value, string $key): mixed
    {
        return ($this->mask)($value, $key);
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public function headers(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $value) {
            // Laravel gives every header as a list of values; a single one is unwrapped.
            if (is_array($value) && count($value) === 1) {
                $value = $value[0];
            }

            // Header names are case-insensitive, so they are matched lowercased.
            $mask = $this->find($this->headers, strtolower((string) $name));
            $out[$name] = $mask === null ? $value : $mask($value, (string) $name);
        }

        return $out;
    }

    /**
     * Cookies named in the cookie list are masked; the rest go through the
     * input pattern like any other key.
     *
     * @param  array<string, mixed>  $cookies
     * @return array<string, mixed>
     */
    public function cookies(array $cookies): array
    {
        $out = [];

        foreach ($cookies as $name => $value) {
            $mask = $this->find($this->cookies, (string) $name);
            $out[$name] = $mask === null ? $value : $mask($value, (string) $name);
        }

        return $this->inputs($out);
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public function inputs(array $input): array
    {
        if ($this->patterns === []) {
            return $input;
        }

        $out = [];

        foreach ($input as $key => $value) {
            if (is_string($key) && $this->matches($key)) {
                $out[$key] = $this->mask($value, $key);

                continue;
            }

            $out[$key] = is_array($value) ? $this->inputs($value) : $value;
        }

        return $out;
    }

    /**
     * Mask the items of response capture cards like input.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    public function cards(array $cards): array
    {
        return array_map(function (array $card): array {
            if (is_array($card['items'] ?? null)) {
                $card['items'] = $this->inputs($card['items']);
            }

            return $card;
        }, $cards);
    }

    /**
     * Mask a JSON or form-encoded body like input. Other bodies cannot be
     * masked reliably and are returned as they are.
     */
    public function body(string $body, ?string $contentType = null): string
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            return (string) json_encode($this->inputs($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($contentType !== null && str_contains(strtolower($contentType), 'application/x-www-form-urlencoded')) {
            parse_str($body, $fields);
            $encoded = http_build_query($this->inputs($fields));

            // Keep the mask's asterisks readable instead of percent-encoded.
            return str_replace('%2A', '*', $encoded);
        }

        return $body;
    }

    private function matches(string $key): bool
    {
        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $list
     * @return array<string, Closure>
     */
    private function masks(array $list, bool $lowercase): array
    {
        $masks = [];

        foreach ($list as $name => $mask) {
            // A plain list entry is a name that uses the default mask.
            if (is_int($name)) {
                $name = (string) $mask;
                $mask = $this->mask;
            }

            if ($lowercase) {
                $name = strtolower($name);
            }

            $masks[$name] = is_callable($mask) ? $mask(...) : $this->mask;
        }

        return $masks;
    }

    /**
     * @param  array<string, Closure>  $masks
     */
    private function find(array $masks, string $name): ?Closure
    {
        foreach ($masks as $pattern => $mask) {
            if (Str::is($pattern, $name)) {
                return $mask;
            }
        }

        return null;
    }
}
