<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Support;

use Closure;

/**
 * Masks secrets in headers and input before they reach storage. The actual
 * replacement is delegated to an invokable configured under `lens.mask.using`.
 */
final class Masker
{
    private readonly Closure $mask;

    /**
     * @param  list<string>  $headers  lowercase header names to mask
     * @param  string|null  $pattern  regex matched against input keys
     */
    public function __construct(
        private readonly array $headers,
        private readonly ?string $pattern,
        callable $mask,
    ) {
        $this->mask = $mask(...);
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
            $out[$name] = in_array(strtolower((string) $name), $this->headers, true)
                ? $this->mask($value, (string) $name)
                : $value;
        }

        return $out;
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
}
