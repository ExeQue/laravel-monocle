<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Request;

use ExeQue\Monocle\Contracts\ResponseCapture;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * One card per Livewire component in an update request: the methods it called,
 * the properties the browser updated, what changed in its data, the events it
 * dispatched, what it returned and how much HTML it sent back.
 */
final readonly class LivewireUpdateCapture implements ResponseCapture
{
    public function __construct(private Eyepiece $eyepiece)
    {
    }

    public function capture(Request $request, Response $response): ?array
    {
        $body = json_decode((string) $response->getContent(), true);

        if (! is_array($body) || ! is_array($body['components'] ?? null)) {
            return null;
        }

        $sent = (array) $request->input('components', []);
        $masker = $this->eyepiece->masker();
        $cards = [];

        foreach ($body['components'] as $index => $component) {
            $after = $this->snapshot($component['snapshot'] ?? null);
            $before = $this->snapshot($sent[$index]['snapshot'] ?? null);
            $effects = (array) ($component['effects'] ?? []);

            $cards[] = [
                'title' => (string) ($after['memo']['name'] ?? 'component'),
                'subtitle' => isset($after['memo']['id']) ? (string) $after['memo']['id'] : null,
                'items' => array_filter([
                    'calls' => array_map($this->call(...), (array) ($sent[$index]['calls'] ?? [])),
                    'updates' => $masker->inputs((array) ($sent[$index]['updates'] ?? [])),
                    'changes' => $this->changes(
                        $masker->inputs($this->unwrap((array) ($before['data'] ?? []))),
                        $masker->inputs($this->unwrap((array) ($after['data'] ?? []))),
                    ),
                    'dispatched' => array_map($this->dispatched(...), (array) ($effects['dispatches'] ?? [])),
                    'returns' => array_values(array_filter((array) ($effects['returns'] ?? []), fn (mixed $value): bool => $value !== null)),
                    'redirect' => $effects['redirect'] ?? null,
                    'html_bytes' => isset($effects['html']) ? strlen((string) $effects['html']) : null,
                ], fn (mixed $value): bool => $value !== null && $value !== []),
            ];
        }

        return $cards;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(mixed $snapshot): array
    {
        $decoded = is_string($snapshot) ? json_decode($snapshot, true) : $snapshot;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Livewire stores non-scalar values as [value, {s: type}]; keep the value.
     */
    private function unwrap(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value) && count($value) === 2 && is_array($value[1]) && isset($value[1]['s'])) {
            return $this->unwrap($value[0]);
        }

        return array_map($this->unwrap(...), $value);
    }

    /**
     * @param  array<string, mixed>  $call
     * @return array{name: string, params: mixed}
     */
    private function call(array $call): array
    {
        $method = (string) ($call['method'] ?? '?');
        $params = (array) ($call['params'] ?? []);

        if ($method === '__dispatch') {
            return $this->event('dispatch '.($params[0] ?? '?'), $params[1] ?? []);
        }

        return ['name' => $method, 'params' => $params === [] ? null : $this->eyepiece->masker()->inputs($params)];
    }

    /**
     * @return array{name: string, params: mixed}
     */
    private function dispatched(mixed $event): array
    {
        return is_array($event) ? $this->event((string) ($event['name'] ?? '?'), $event['params'] ?? []) : ['name' => (string) $event, 'params' => null];
    }

    /**
     * @return array{name: string, params: mixed}
     */
    private function event(string $name, mixed $params): array
    {
        $params = $this->eyepiece->masker()->inputs((array) $params);

        return ['name' => $name, 'params' => $params === [] ? null : $params];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{field: string, from: mixed, to: mixed}>
     */
    private function changes(array $before, array $after): array
    {
        $from = Arr::dot($before);
        $to = Arr::dot($after);
        $changes = [];

        foreach (array_unique([...array_keys($from), ...array_keys($to)]) as $field) {
            if (($from[$field] ?? null) !== ($to[$field] ?? null) || array_key_exists($field, $from) !== array_key_exists($field, $to)) {
                $changes[] = ['field' => (string) $field, 'from' => $from[$field] ?? null, 'to' => $to[$field] ?? null];
            }
        }

        return $changes;
    }
}
