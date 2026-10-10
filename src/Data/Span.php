<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Data;

use Closure;
use ExeQue\Monocle\Enums\SpanColor;
use ExeQue\Monocle\Exceptions\InvalidSpanColorException;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Normalizer;

/**
 * A timed interval inside a capture. Handles stay mutable until the capture is
 * finalized, so metadata can be attached before start(), while running and
 * after stop().
 */
final class Span
{
    public ?float $start = null;

    public ?float $end = null;

    public ?string $endedBy = null;

    /** @var array<string, mixed> */
    public array $meta = [];

    /** @var array<string, mixed>|null */
    public ?array $origin = null;

    /** A SpanColor value or a hex colour; null draws the kind's own colour. */
    public ?string $color = null;

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $kind,
        public ?string $parentId,
        public readonly ?string $key,
        private readonly ?Eyepiece $eyepiece,
    ) {
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function start(array $meta = []): self
    {
        $this->meta($meta);

        if ($this->start !== null) {
            return $this;
        }

        $this->eyepiece?->startSpan($this);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function stop(array $meta = []): self
    {
        $this->meta($meta);

        if ($this->end !== null) {
            return $this;
        }

        if ($this->start === null) {
            $this->eyepiece?->startSpan($this);
        }

        $this->eyepiece?->stopSpan($this);

        return $this;
    }

    /**
     * Merge metadata into the span; later keys win.
     *
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta): self
    {
        if ($meta !== []) {
            $this->meta = array_merge($this->meta, Normalizer::normalize($meta));
        }

        return $this;
    }

    /**
     * Draw this span in a colour of its own on the dashboard timeline.
     *
     * @throws InvalidSpanColorException
     */
    public function color(SpanColor|string $color): self
    {
        if ($color instanceof SpanColor) {
            $this->color = $color->value;

            return $this;
        }

        if (SpanColor::tryFrom($color) === null && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $color) !== 1) {
            throw InvalidSpanColorException::forValue($color);
        }

        $this->color = $color;

        return $this;
    }

    public function parent(?Span $parent): self
    {
        $this->parentId = $parent?->id;

        return $this;
    }

    /**
     * Run the callback inside this span and return its result.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        $this->start();

        try {
            return $callback();
        } finally {
            $this->stop();
        }
    }

    public function duration(): ?float
    {
        if ($this->start === null || $this->end === null) {
            return null;
        }

        return round($this->end - $this->start, 3);
    }

    public function isOpen(): bool
    {
        return $this->start !== null && $this->end === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parentId,
            'kind' => $this->kind,
            'name' => $this->name,
            'key' => $this->key,
            'start' => $this->start,
            'end' => $this->end,
            'duration' => $this->duration(),
            'ended_by' => $this->endedBy,
            'meta' => $this->meta,
            'origin' => $this->origin,
            'color' => $this->color,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $span = new self(
            id: $data['id'],
            name: $data['name'],
            kind: $data['kind'],
            parentId: $data['parent_id'] ?? null,
            key: $data['key'] ?? null,
            eyepiece: null,
        );

        $span->start = $data['start'] ?? null;
        $span->end = $data['end'] ?? null;
        $span->endedBy = $data['ended_by'] ?? null;
        $span->color = isset($data['color']) && is_string($data['color']) ? $data['color'] : null;
        $span->meta = $data['meta'] ?? [];
        $span->origin = $data['origin'] ?? null;

        return $span;
    }
}
