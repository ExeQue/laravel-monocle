<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Livewire;

use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
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
final readonly class LivewireUpdateCapture implements RequiresPackages, ResponseCapture
{
    public static function packages(): array
    {
        return ['livewire/livewire'];
    }

    public function __construct(
        private Eyepiece $eyepiece
    ) {
    }

    public function capture(Request $request, Response $response): ?array
    {
        $body = json_decode((string) $response->getContent(), true);

        if (! is_array($body)) {
            return null;
        }

        if (! is_array($body['components'] ?? null)) {
            return null;
        }

        // The request lists the components in the same order the response does.
        $sent = (array) $request->input('components', []);
        $cards = [];

        foreach ($body['components'] as $index => $component) {
            $cards[] = $this->card($component, $sent[$index] ?? []);
        }

        return $cards;
    }

    /**
     * @param  mixed  $component  the component as the response returned it
     * @param  mixed  $sent  the same component as the request sent it
     * @return array{title: string, subtitle: ?string, items: array<string, mixed>}
     */
    private function card(mixed $component, mixed $sent): array
    {
        $masker = $this->eyepiece->masker();
        $after = $this->snapshot($component['snapshot'] ?? null);
        $before = $this->snapshot($sent['snapshot'] ?? null);
        $effects = (array) ($component['effects'] ?? []);

        $dataBefore = $masker->inputs($this->unwrap((array) ($before['data'] ?? [])));
        $dataAfter = $masker->inputs($this->unwrap((array) ($after['data'] ?? [])));
        $returns = array_filter((array) ($effects['returns'] ?? []), fn (mixed $value): bool => $value !== null);

        $items = [
            'calls' => array_map($this->call(...), (array) ($sent['calls'] ?? [])),
            'updates' => $masker->inputs((array) ($sent['updates'] ?? [])),
            'changes' => $this->changes($dataBefore, $dataAfter),
            'dispatched' => array_map($this->dispatched(...), (array) ($effects['dispatches'] ?? [])),
            'returns' => array_values($returns),
            'redirect' => $effects['redirect'] ?? null,
            'html_bytes' => isset($effects['html']) ? strlen((string) $effects['html']) : null,
        ];

        return [
            'title' => (string) ($after['memo']['name'] ?? 'component'),
            'subtitle' => isset($after['memo']['id']) ? (string) $after['memo']['id'] : null,
            'items' => array_filter($items, fn (mixed $value): bool => $value !== null && $value !== []),
        ];
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

        if ($this->isTuple($value)) {
            return $this->unwrap($value[0]);
        }

        return array_map($this->unwrap(...), $value);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function isTuple(array $value): bool
    {
        if (! array_is_list($value)) {
            return false;
        }

        if (count($value) !== 2) {
            return false;
        }

        return is_array($value[1]) && isset($value[1]['s']);
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
        if (! is_array($event)) {
            return ['name' => (string) $event, 'params' => null];
        }

        return $this->event((string) ($event['name'] ?? '?'), $event['params'] ?? []);
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
            $field = (string) $field;

            if ($this->becameParent($field, $from, $to)) {
                continue;
            }

            if ($this->becameParent($field, $to, $from)) {
                continue;
            }

            $old = $from[$field] ?? null;
            $new = $to[$field] ?? null;

            // A key added or removed with a null value is a change too.
            $existenceChanged = array_key_exists($field, $from) !== array_key_exists($field, $to);

            if ($old !== $new || $existenceChanged) {
                $changes[] = ['field' => $field, 'from' => $old, 'to' => $new];
            }
        }

        return $changes;
    }

    /**
     * An empty list on one side that holds items on the other: the items are
     * listed as changes on their own, so the list itself is not.
     *
     * @param  array<string, mixed>  $side
     * @param  array<string, mixed>  $other
     */
    private function becameParent(string $field, array $side, array $other): bool
    {
        if (! array_key_exists($field, $side)) {
            return false;
        }

        if ($side[$field] !== []) {
            return false;
        }

        if (array_key_exists($field, $other)) {
            return false;
        }

        foreach (array_keys($other) as $key) {
            if (str_starts_with((string) $key, $field.'.')) {
                return true;
            }
        }

        return false;
    }
}
