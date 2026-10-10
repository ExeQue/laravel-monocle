<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Reader;

/**
 * Filters applied to index rows when listing captures.
 */
final class Filter
{
    public function __construct(
        public readonly ?string $type = null,
        public readonly ?string $uri = null,
        public readonly ?int $status = null,
        public readonly ?float $minDuration = null,
        public readonly ?float $since = null,
        public readonly ?string $label = null,
        public readonly ?bool $hasErrors = null,
        public readonly ?string $traceId = null,
        public readonly ?string $parentId = null,
        /** @var list<string> Sections that must contain at least one row. */
        public readonly array $has = [],
        /** @var list<string> Captures whose name contains any of these, or whose route name is one of them, are left out. */
        public readonly array $exclude = [],
        public readonly int $offset = 0,
        public readonly int $limit = 20,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            type: self::string($input['type'] ?? null),
            uri: self::string($input['uri'] ?? null),
            status: isset($input['status']) ? (int) $input['status'] : null,
            minDuration: isset($input['min_duration_ms']) ? (float) $input['min_duration_ms'] : null,
            since: self::since($input['since'] ?? null),
            label: self::string($input['label'] ?? null),
            hasErrors: isset($input['has_errors']) ? filter_var($input['has_errors'], FILTER_VALIDATE_BOOL) : null,
            traceId: self::string($input['trace_id'] ?? null),
            parentId: self::string($input['parent_id'] ?? null),
            has: array_values(array_filter(array_map('strval', (array) ($input['has'] ?? [])))),
            exclude: array_values(array_filter(array_map('strval', (array) ($input['exclude'] ?? [])))),
            offset: max(0, (int) ($input['offset'] ?? 0)),
            limit: max(1, min(500, (int) ($input['limit'] ?? 20))),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function matches(array $row): bool
    {
        if ($this->type !== null && ($row['type'] ?? null) !== $this->type) {
            return false;
        }

        if ($this->uri !== null && ! str_contains(strtolower((string) ($row['name'] ?? '')), strtolower($this->uri))) {
            return false;
        }

        if ($this->status !== null && (int) ($row['status'] ?? 0) !== $this->status) {
            return false;
        }

        if ($this->minDuration !== null && (float) ($row['duration'] ?? 0) < $this->minDuration) {
            return false;
        }

        if ($this->since !== null && (float) ($row['started_at'] ?? 0) < $this->since) {
            return false;
        }

        if ($this->label !== null && ($row['label'] ?? null) !== $this->label) {
            return false;
        }

        if ($this->hasErrors !== null && (bool) ($row['has_errors'] ?? false) !== $this->hasErrors) {
            return false;
        }

        if ($this->traceId !== null && ($row['trace_id'] ?? null) !== $this->traceId) {
            return false;
        }

        if ($this->parentId !== null && ($row['parent_id'] ?? null) !== $this->parentId) {
            return false;
        }

        foreach ($this->exclude as $part) {
            if (str_contains(strtolower((string) ($row['name'] ?? '')), strtolower($part)) || ($row['route'] ?? null) === $part) {
                return false;
            }
        }

        foreach ($this->has as $section) {
            if ((int) ($row['counts'][$section] ?? 0) === 0) {
                return false;
            }
        }

        return true;
    }

    private static function string(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * Accepts a unix timestamp, an ISO date or a relative expression like "15m" / "2h".
     */
    private static function since(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (preg_match('/^(\d+)\s*([smhd])$/i', (string) $value, $matches) === 1) {
            $seconds = (int) $matches[1] * match (strtolower($matches[2])) {
                's' => 1,
                'm' => 60,
                'h' => 3600,
                default => 86400,
            };

            return microtime(true) - $seconds;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : (float) $timestamp;
    }
}
