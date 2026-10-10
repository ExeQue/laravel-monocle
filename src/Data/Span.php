<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Data;

use Closure;
use ExeQue\Monocle\Recorder;
use ExeQue\Monocle\Support\Normalizer;

/**
 * A timed interval inside an entry. Handles stay mutable until the entry is
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

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $kind,
        public ?string $parentId,
        public readonly ?string $key,
        private readonly ?Recorder $recorder,
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

        $this->recorder?->startSpan($this);

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
            $this->recorder?->startSpan($this);
        }

        $this->recorder?->stopSpan($this);

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
            recorder: null,
        );

        $span->start = $data['start'] ?? null;
        $span->end = $data['end'] ?? null;
        $span->endedBy = $data['ended_by'] ?? null;
        $span->meta = $data['meta'] ?? [];
        $span->origin = $data['origin'] ?? null;

        return $span;
    }
}
