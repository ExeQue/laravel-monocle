<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Support;

use Closure;
use Illuminate\Support\Str;

/**
 * Masks secrets in headers and input before they reach storage. The actual
 * replacement is delegated to an invokable configured under `lens.mask.using`.
 */
final class Masker
{
    private readonly Closure $mask;

    /** @var array<string, Closure> */
    private readonly array $headers;

    /** @var array<string, Closure> */
    private readonly array $cookies;

    /**
     * Header and cookie lists map a name (wildcards allowed) to the mask used
     * for it; a plain list entry uses the default mask.
     *
     * @param  array<array-key, mixed>  $headers  header name => mask, or header name
     * @param  string|null  $pattern  regex matched against input keys
     * @param  array<array-key, mixed>  $cookies  cookie name => mask, or cookie name
     */
    public function __construct(
        array $headers,
        private readonly ?string $pattern,
        callable $mask,
        array $cookies = [],
    ) {
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
        if ($this->pattern === null || $this->pattern === '') {
            return $input;
        }

        $out = [];

        foreach ($input as $key => $value) {
            if (is_string($key) && preg_match($this->pattern, $key) === 1) {
                $out[$key] = $this->mask($value, $key);

                continue;
            }

            $out[$key] = is_array($value) ? $this->inputs($value) : $value;
        }

        return $out;
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
