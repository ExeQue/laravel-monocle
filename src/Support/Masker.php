<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Support;

use Closure;
use Illuminate\Support\Str;

/**
 * Masks secrets in headers and input before they reach storage. The actual
 * replacement is delegated to an invokable configured under `monocle.mask`.
 *
 * @internal
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

    /**
     * Header and cookie lists map a name (wildcards allowed) to the mask used
     * for it; a plain list entry uses the default mask.
     *
     * @param  array<array-key, mixed>  $headers  header name => mask, or header name
     * @param  string|list<string>|null  $pattern  regular expressions matched against input keys
     * @param  array<array-key, mixed>  $cookies  cookie name => mask, or cookie name
     */
    public function __construct(
        array $headers,
        string|array|null $pattern,
        callable $mask,
        array $cookies = [],
    ) {
        $this->patterns = array_values(array_filter((array) $pattern, fn (mixed $value): bool => is_string($value) && $value !== ''));
        $this->mask = $mask(...);
        $this->headers = $this->masks($headers, lowercase: true);
        $this->cookies = $this->masks($cookies, lowercase: false);
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
            $value = is_array($value) && count($value) === 1 ? $value[0] : $value;
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

            return str_replace('%2A', '*', http_build_query($this->inputs($fields)));
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
            if (is_int($name)) {
                [$name, $mask] = [(string) $mask, $this->mask];
            }

            $masks[$lowercase ? strtolower($name) : $name] = is_callable($mask) ? $mask(...) : $this->mask;
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
