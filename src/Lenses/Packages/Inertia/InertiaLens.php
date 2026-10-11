<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Inertia;

use Closure;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Format;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Inertia\AlwaysProp;
use Inertia\DeferProp;
use Inertia\MergeProp;
use Inertia\OnceProp;
use Inertia\OptionalProp;
use Inertia\Response as InertiaResponse;
use Inertia\ScrollProp;
use ReflectionFunction;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Inertia page responses: the component, the visit and every prop with its
 * type, size and whether it was sent. Inertia fires no events, so the router
 * hands over the Inertia response before it is turned into HTML or JSON
 * (PreparingResponse, for the declared props and their types) and the
 * result right after (ResponsePrepared, for the page that was sent).
 */
final class InertiaLens implements Advises, Configurable, Lens, Presentable, RequiresPackages, Resettable, Summarizable
{
    private int $largePropBytes = 50000;

    private int $largePageBytes = 200000;

    private int $maxValueBytes = 20000;

    private ?InertiaResponse $pending = null;

    /** @var array{types: array<string, string>, sources: array<string, string>} what the props were declared as, before timing */
    private array $declared = ['types' => [], 'sources' => []];

    /** @var array<string, array{time: float, duration: float}> prop => when it resolved and how long it took */
    private array $timings = [];

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'inertia';
    }

    public static function packages(): array
    {
        return ['inertiajs/inertia-laravel'];
    }

    public function description(): string
    {
        return 'inertiajs/inertia-laravel page responses. Section inertia has one row per top-level prop: prop, type (plain, closure, always, optional, lazy, defer, merge, scroll, once), shared, sent, size_bytes (JSON size when sent), group (defer group), merge (append, prepend, deep), omitted (why it was not sent: deferred, partial reload, optional, loaded once, rescued), value (the value sent, masked like request input; left out over max_value_bytes), defined_at (file:line of the closure a deferred, optional or lazy prop resolves with), duration and time (how long that closure took to resolve, also an inertia.prop span on the timeline), large and shared_on_partial. Section inertia_pages has one row per page: component, url, version, visit (first load or inertia), partial (component, only, except), props_sent, props_omitted, payload_bytes, deferred groups, encrypt_history and clear_history.';
    }

    public function advice(): string
    {
        return 'Look here when an Inertia page is slow or heavy: which props were sent, how big they are, and which were deferred or left out by a partial reload.';
    }

    public function configure(array $options): void
    {
        $this->largePropBytes = (int) ($options['large_prop_bytes'] ?? $this->largePropBytes);
        $this->largePageBytes = (int) ($options['large_page_bytes'] ?? $this->largePageBytes);
        $this->maxValueBytes = (int) ($options['max_value_bytes'] ?? $this->maxValueBytes);
    }

    public function boot(): void
    {
        if (! class_exists(InertiaResponse::class)) {
            return;
        }

        $this->eyepiece->listen(PreparingResponse::class, function (PreparingResponse $event): void {
            if ($event->response instanceof InertiaResponse) {
                $this->pending = $event->response;
                $this->prepare($event->response);
            }
        });

        $this->eyepiece->listen(ResponsePrepared::class, function (ResponsePrepared $event): void {
            $inertia = $this->pending;

            if ($inertia === null) {
                return;
            }

            $this->pending = null;

            if (! $this->eyepiece->recording()) {
                return;
            }

            $page = $this->page($event->response);

            if ($page === null) {
                return;
            }

            $this->record($event->request, $inertia, $page);
        });
    }

    public function reset(): void
    {
        $this->pending = null;
        $this->declared = ['types' => [], 'sources' => []];
        $this->timings = [];
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function record(Request $request, InertiaResponse $inertia, array $page): void
    {
        [$shared] = (fn (): array => [$this->sharedProps])->call($inertia);

        $sent = (array) ($page['props'] ?? []);
        $partial = $request->headers->get('X-Inertia-Partial-Component') === ($page['component'] ?? null);
        $only = $partial ? $this->list($request->headers->get('X-Inertia-Partial-Data')) : [];
        $except = $partial ? $this->list($request->headers->get('X-Inertia-Partial-Except')) : [];
        $sharedKeys = array_unique([...$this->topLevel(array_keys($shared)), ...(array) ($page['sharedProps'] ?? [])]);
        ['types' => $types, 'sources' => $sources] = $this->declared;
        $groups = $this->groups((array) ($page['deferredProps'] ?? []));
        $merges = [
            ...$this->flag((array) ($page['mergeProps'] ?? []), 'append'),
            ...$this->flag((array) ($page['prependProps'] ?? []), 'prepend'),
            ...$this->flag((array) ($page['deepMergeProps'] ?? []), 'deep'),
        ];

        $names = array_values(array_unique([...array_keys($types), ...array_map('strval', array_keys($sent))]));
        $omitted = 0;

        foreach ($names as $name) {
            $type = $types[$name] ?? 'plain';
            $isSent = array_key_exists($name, $sent);
            $isShared = in_array($name, $sharedKeys, true);
            $size = $isSent ? strlen((string) $this->eyepiece->outside(fn () => json_encode($sent[$name], JSON_PARTIAL_OUTPUT_ON_ERROR))) : null;
            $omitted += $isSent ? 0 : 1;

            $this->eyepiece->push('inertia', [
                'prop' => $name,
                'type' => $type,
                'shared' => $isShared,
                'sent' => $isSent,
                'size_bytes' => $size,
                'group' => $groups[$name] ?? null,
                'merge' => $merges[$name] ?? null,
                'omitted' => $isSent ? null : $this->omitted($name, $type, $page, $groups, $partial),
                'defined_at' => $sources[$name] ?? null,
                'duration' => $this->timings[$name]['duration'] ?? null,
                'time' => $this->timings[$name]['time'] ?? null,
                'value' => $isSent ? $this->value($name, $sent[$name], (int) $size) : null,
                'large' => $size !== null && $size > $this->largePropBytes ? true : null,
                'shared_on_partial' => $this->sharedOnPartial($partial, $isShared, $isSent, $type, $name, $only) ? true : null,
            ], origin: false);
        }

        $this->eyepiece->push('inertia_pages', [
            'component' => $page['component'] ?? null,
            'url' => $page['url'] ?? null,
            'version' => $page['version'] ?? null,
            'visit' => $request->headers->has('X-Inertia') ? 'inertia' : 'first load',
            'partial' => $partial ? array_filter(['component' => $page['component'], 'only' => $only, 'except' => $except]) : null,
            'props_sent' => count($sent),
            'props_omitted' => $omitted,
            'payload_bytes' => strlen((string) $this->eyepiece->outside(fn () => json_encode($page, JSON_PARTIAL_OUTPUT_ON_ERROR))),
            'deferred' => ($page['deferredProps'] ?? []) ?: null,
            'encrypt_history' => (bool) ($page['encryptHistory'] ?? false),
            'clear_history' => (bool) ($page['clearHistory'] ?? false),
        ], origin: false);
    }

    /**
     * The page object: the JSON of an Inertia visit, or the page handed to
     * the root view on a first load.
     *
     * @return array<string, mixed>|null
     */
    private function page(Response $response): ?array
    {
        if ($response instanceof JsonResponse) {
            $page = json_decode((string) $response->getContent(), true);

            return is_array($page) ? $page : null;
        }

        if (! $response instanceof HttpResponse) {
            return null;
        }

        if (! $response->original instanceof View) {
            return null;
        }

        $page = $response->original->getData()['page'] ?? null;

        return is_array($page) ? $page : null;
    }

    /**
     * @param  array<array-key, mixed>  $props
     * @return array<string, string>
     */
    private function types(array $props): array
    {
        $types = [];

        foreach ($props as $key => $value) {
            if (is_int($key)) {
                continue;
            }

            $top = $this->topLevel([$key])[0];
            $types[$top] = $top === $key ? $this->type($value) : ($types[$top] ?? 'plain');
        }

        return $types;
    }

    /**
     * Notes what each prop was declared as, then has every closure a prop
     * resolves with timed: a span on the timeline (with the queries it runs
     * under it) and a duration on the prop's row. The closure is still
     * called through the container, so its parameters are injected as before.
     */
    private function prepare(InertiaResponse $inertia): void
    {
        [$shared, $props] = (fn (): array => [$this->sharedProps, $this->props])->call($inertia);

        $this->declared = [
            'types' => [...$this->types($shared), ...$this->types($props)],
            'sources' => [...$this->sources($shared), ...$this->sources($props)],
        ];
        $this->timings = [];

        if (! $this->eyepiece->recording()) {
            return;
        }

        $time = function (array $props): array {
            foreach ($props as $key => $value) {
                if (is_string($key)) {
                    $props[$key] = $this->timed($this->topLevel([$key])[0], $value);
                }
            }

            return $props;
        };

        (function (array $shared, array $props): void {
            $this->sharedProps = $shared;
            $this->props = $props;
        })->call($inertia, $time($shared), $time($props));
    }

    private function timed(string $name, mixed $value): mixed
    {
        if ($value instanceof Closure) {
            return $this->timer($name, $value);
        }

        if (! is_object($value)) {
            return $value;
        }

        if (! str_starts_with($value::class, 'Inertia\\')) {
            return $value;
        }

        $timer = $this->timer(...);

        // Prop classes keep their resolver in a protected callback (or value) property.
        (function () use ($name, $timer): void {
            foreach (['callback', 'value'] as $property) {
                if (property_exists($this, $property) && $this->{$property} instanceof Closure) {
                    $this->{$property} = $timer($name, $this->{$property});

                    return;
                }
            }
        })->call($value);

        return $value;
    }

    private function timer(string $name, Closure $resolver): Closure
    {
        return function () use ($name, $resolver): mixed {
            $span = $this->eyepiece->span($name, 'inertia.prop')->start();
            $started = $this->eyepiece->now();

            try {
                return App::call($resolver);
            } finally {
                $span->stop();
                $this->timings[$name] = ['time' => round($started, 3), 'duration' => round($this->eyepiece->now() - $started, 3)];
            }
        };
    }

    /**
     * Where each prop that resolves through a closure (deferred, optional,
     * lazy and the like) defines it, as file:line.
     *
     * @param  array<array-key, mixed>  $props
     * @return array<string, string>
     */
    private function sources(array $props): array
    {
        $sources = [];

        foreach ($props as $key => $value) {
            if (is_int($key)) {
                continue;
            }

            $source = $this->source($value);

            if ($source !== null) {
                $sources[$this->topLevel([$key])[0]] = $source;
            }
        }

        return $sources;
    }

    private function source(mixed $value): ?string
    {
        $callable = $value instanceof Closure ? $value : $this->resolver($value);

        if (! $callable instanceof Closure) {
            return null;
        }

        try {
            $function = new ReflectionFunction($callable);
        } catch (Throwable) {
            return null;
        }

        $file = (string) $function->getFileName();

        if ($file === '' || str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return Str::after($file, base_path().DIRECTORY_SEPARATOR).':'.$function->getStartLine();
    }

    /**
     * The closure an Inertia prop class resolves with, kept in its protected
     * callback (or value) property. Other objects, such as collections, are
     * left alone.
     */
    private function resolver(mixed $value): mixed
    {
        if (! is_object($value)) {
            return null;
        }

        if (! str_starts_with($value::class, 'Inertia\\')) {
            return null;
        }

        return (function (): mixed {
            foreach (['callback', 'value'] as $property) {
                if (property_exists($this, $property)) {
                    return $this->{$property};
                }
            }

            return null;
        })->call($value);
    }

    /**
     * The value as sent, masked like request input; values over
     * max_value_bytes are left out to keep captures small.
     */
    private function value(string $name, mixed $value, int $size): mixed
    {
        if ($size > $this->maxValueBytes) {
            return sprintf('(%s, larger than max_value_bytes)', Format::bytes($size));
        }

        return $this->eyepiece->masker(self::class)->inputs([$name => $value])[$name] ?? null;
    }

    private function type(mixed $value): string
    {
        return match (true) {
            $value instanceof AlwaysProp => 'always',
            $value instanceof DeferProp => 'defer',
            $value instanceof OptionalProp => 'optional',
            is_a($value, 'Inertia\LazyProp') => 'lazy',
            $value instanceof ScrollProp => 'scroll',
            $value instanceof MergeProp => 'merge',
            $value instanceof OnceProp => 'once',
            $value instanceof Closure => 'closure',
            default => 'plain',
        };
    }

    /**
     * @param  array<array-key, mixed>  $page
     * @param  array<string, string>  $groups
     */
    private function omitted(string $name, string $type, array $page, array $groups, bool $partial): string
    {
        if (in_array($name, $this->topLevel((array) ($page['rescuedProps'] ?? [])), true)) {
            return 'rescued';
        }

        if (isset($groups[$name])) {
            return 'deferred';
        }

        if ($partial) {
            return 'partial reload';
        }

        if (in_array($type, ['optional', 'lazy'], true)) {
            return 'optional';
        }

        if (in_array($name, $this->topLevel(array_column((array) ($page['onceProps'] ?? []), 'prop')), true)) {
            return 'loaded once';
        }

        return 'omitted';
    }

    /**
     * A shared prop the partial reload did not ask for but got anyway, such
     * as everything shared on a reload with only an except list.
     *
     * @param  list<string>  $only
     */
    private function sharedOnPartial(bool $partial, bool $shared, bool $sent, string $type, string $name, array $only): bool
    {
        if (! $partial) {
            return false;
        }

        if (! $shared) {
            return false;
        }

        if (! $sent) {
            return false;
        }

        if ($type === 'always') {
            return false;
        }

        return ! in_array($name, $only, true);
    }

    /**
     * @param  array<string, list<string>>  $deferred
     * @return array<string, string>
     */
    private function groups(array $deferred): array
    {
        $groups = [];

        foreach ($deferred as $group => $paths) {
            foreach ($this->topLevel((array) $paths) as $prop) {
                $groups[$prop] = (string) $group;
            }
        }

        return $groups;
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, string>
     */
    private function flag(array $paths, string $value): array
    {
        return array_fill_keys($this->topLevel($paths), $value);
    }

    /**
     * @param  array<array-key, mixed>  $keys
     * @return list<string>
     */
    private function topLevel(array $keys): array
    {
        return array_values(array_unique(array_map(fn (mixed $key): string => explode('.', (string) $key)[0], $keys)));
    }

    /**
     * @return list<string>
     */
    private function list(?string $header): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $header))));
    }

    public function summarize(Capture $capture): Summary
    {
        $pages = $capture->rows('inertia_pages');

        if ($pages === []) {
            return Summary::make();
        }

        $props = $capture->rows('inertia');
        $sent = array_filter($props, fn (array $row): bool => (bool) ($row['sent'] ?? false));
        $warnings = [];
        $component = $pages[0]['component'] ?? '?';

        foreach ($props as $row) {
            $where = isset($row['defined_at']) ? ', defined at '.$row['defined_at'] : '';

            if ($row['large'] ?? false) {
                $warnings[] = sprintf('Inertia prop "%s" on %s is %s%s.', $row['prop'], $component, Format::bytes($row['size_bytes']), $where);
            }

            if ($row['shared_on_partial'] ?? false) {
                $warnings[] = sprintf('Shared Inertia prop "%s" was sent on a partial reload of %s that did not ask for it%s.', $row['prop'], $component, $where);
            }
        }

        foreach ($pages as $page) {
            if ((int) ($page['payload_bytes'] ?? 0) > $this->largePageBytes) {
                $warnings[] = sprintf('Inertia page %s is %s.', $page['component'], Format::bytes($page['payload_bytes']));
            }
        }

        return Summary::make()
            ->total('inertia_ms', round(array_sum(array_map(fn (array $row): float => (float) ($row['duration'] ?? 0), $props)), 3), 'spent', Unit::Milliseconds)
            ->total('inertia_props', count($sent), 'props sent')
            ->total('inertia_payload_bytes', array_sum(array_column($pages, 'payload_bytes')), 'payload', Unit::Bytes)
            ->total('inertia_deferred', count(array_filter($props, fn (array $row): bool => ($row['omitted'] ?? null) === 'deferred')), 'deferred')
            ->warnings($warnings);
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.inertia')
            ->includes(['inertia_pages'])
            ->columns(['shared' => 'Scope', 'prop', 'type', 'sent', 'size_bytes', 'group', 'merge', 'omitted', 'duration', 'defined_at' => 'Defined at'])
            ->detailsOnly(['value'])
            ->format(['shared' => ['flag', 'shared', 'page', 'muted', 'muted'], 'sent' => ['flag', 'sent', 'not sent', 'muted']])
            ->highlight(['large' => 'warn', 'shared_on_partial' => 'warn'])
            ->groupBy(['type' => 'by type', 'shared' => 'by scope']);
    }
}
