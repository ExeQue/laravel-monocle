<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Data;

use ExeQue\Monocle\Support\Concerns\Immutable;
use Illuminate\Support\Arr;

/**
 * What a capture made of a request and its response for the dashboard:
 * payload fields in a format, and the response as cards. A side left
 * alone is kept as it is. Every fluent method returns a new instance.
 */
final readonly class Formatted
{
    use Immutable;

    /**
     * @param  array<string, array{format?: string, text?: string, value?: mixed}>|null  $request  payload fields by dotted key
     * @param  list<array{title: string, subtitle?: string|null, items: array<string, mixed>, formats?: array<string, string>}>|null  $response  cards
     */
    private function __construct(
        public ?array $request = null,
        public ?array $response = null,
    ) {
    }

    /**
     * One more card for the response.
     */
    public function card(Card $card): self
    {
        return $this->with(['response' => [...$this->response ?? [], $card->toArray()]]);
    }

    /**
     * Show a payload field (dotted key) in a format (`graphql`), with the
     * text to show instead of its value, such as the value printed neatly.
     */
    public function field(string $key, string $format, ?string $text = null): self
    {
        return $this->request($key, array_filter(['format' => $format, 'text' => $text], fn (?string $value): bool => $value !== null));
    }

    /**
     * Record this value for a payload field (dotted key) instead of the one
     * sent, such as the value with secrets inside it masked.
     */
    public function replace(string $key, mixed $value): self
    {
        return $this->request($key, ['value' => $value]);
    }

    /**
     * The payload to record and the formats to show it in: the masked
     * payload with the values replaced, and the formats of the fields
     * input masking left alone. A field input masking changed keeps its
     * mask and gets no format, so its text cannot show what the mask hides.
     *
     * @param  array<array-key, mixed>  $sent  the payload as it was sent
     * @param  array<array-key, mixed>  $masked  the same payload with input masking applied
     * @return array{0: array<array-key, mixed>, 1: array<string, array{format: string, text?: string}>}
     */
    public function payload(array $sent, array $masked): array
    {
        $formats = [];

        foreach ($this->request ?? [] as $key => $field) {
            if (Arr::get($masked, $key) !== Arr::get($sent, $key)) {
                continue;
            }

            if (array_key_exists('value', $field)) {
                Arr::set($masked, $key, $field['value']);
            }

            if (isset($field['format'])) {
                $formats[$key] = Arr::only($field, ['format', 'text']);
            }
        }

        return [$masked, $formats];
    }

    /**
     * @param  array{format?: string, text?: string, value?: mixed}  $field
     */
    private function request(string $key, array $field): self
    {
        $request = $this->request ?? [];
        $request[$key] = [...$request[$key] ?? [], ...$field];

        return $this->with(['request' => $request]);
    }
}
