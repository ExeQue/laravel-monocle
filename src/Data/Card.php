<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Data;

use ExeQue\Monocle\Support\Concerns\Immutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Enumerable;

/**
 * One card of a formatted response: a title, an optional subtitle and
 * named items, each shown in an optional format. Every fluent method
 * returns a new card.
 */
final readonly class Card
{
    use Immutable;

    /**
     * @param  array<string, mixed>  $items  name => value: text, numbers, lists, maps, or lists of field/from/to changes
     * @param  array<string, string>  $formats  item name => format (`graphql`)
     */
    private function __construct(
        public string $title = '',
        public ?string $subtitle = null,
        public array $items = [],
        public array $formats = [],
    ) {
    }

    public static function titled(string $title): self
    {
        return new self($title);
    }

    public function subtitle(?string $subtitle): self
    {
        return $this->with(['subtitle' => $subtitle]);
    }

    /**
     * One item, optionally shown in a format (`graphql` colours a GraphQL
     * document).
     */
    public function item(string $name, mixed $value, ?string $format = null): self
    {
        $value = $value instanceof Enumerable ? $value->all() : $value;
        $formats = $this->formats;

        if ($format === null) {
            unset($formats[$name]);
        } else {
            $formats[$name] = $format;
        }

        return $this->with(['items' => [...$this->items, $name => $value], 'formats' => $formats]);
    }

    /**
     * More items, after the ones the card has.
     *
     * @param  array<string, mixed>|Enumerable<string, mixed>  $items
     */
    public function items(array|Enumerable $items): self
    {
        return $this->with(['items' => [...$this->items, ...Arr::from($items)]]);
    }

    /**
     * @return array{title: string, subtitle: string|null, items: array<string, mixed>, formats?: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'items' => $this->items,
            ...$this->formats === [] ? [] : ['formats' => $this->formats],
        ];
    }
}
