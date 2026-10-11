<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Dashboard;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Editor\EditorLinks;
use ExeQue\Monocle\Enums\SpanColor;
use Illuminate\Support\Arr;

/**
 * Builds the dashboard timeline: the span tree, with every timed row
 * (queries, cache, redis, http, filesystem) placed under the deepest span it
 * ran inside, and editor links on files and classes.
 *
 * @internal
 */
final readonly class TimelinePresenter
{
    /** Section => node kind. */
    private const array TIMED_SECTIONS = ['queries' => 'db', 'cache' => 'cache', 'redis' => 'redis', 'http' => 'http', 'filesystem' => 'filesystem'];

    /** Row keys shown elsewhere in the node. */
    private const array HIDDEN_KEYS = ['time', 'duration', 'origin', 'sql', 'sql_with_bindings', 'shape', 'shape_hash', 'n'];

    public function __construct(
        private EditorLinks $links
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function present(Capture $capture): array
    {
        $nodes = [];
        $depth = [];

        foreach ($capture->spans as $span) {
            $nodes[$span->id] = $this->spanNode($span, $capture);
            $depth[$span->id] = $this->depth($span, $capture);
        }

        foreach ($this->timedRowNodes($capture, $depth) as $id => $node) {
            $nodes[$id] = $node;
        }

        $nodes = $this->pools($nodes, $capture);

        return $this->tree($nodes);
    }

    /**
     * A node per timed row, hung under the deepest span it ran inside. Rows
     * without a start time cannot be placed and are left out.
     *
     * @param  array<string, int>  $depth
     * @return array<string, array<string, mixed>>
     */
    private function timedRowNodes(Capture $capture, array $depth): array
    {
        $nodes = [];

        foreach (self::TIMED_SECTIONS as $section => $kind) {
            foreach ($capture->rows($section) as $index => $row) {
                if (! is_numeric($row['time'] ?? null)) {
                    continue;
                }

                $node = $this->rowNode("{$section}:{$index}", $kind, $section, $row);
                $node['parent'] = $this->container($node['s'], $node['e'], $capture, $depth);
                $nodes[$node['id']] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @return array<string, mixed>
     */
    private function spanNode(Span $span, Capture $capture): array
    {
        // A span whose parent was not recorded becomes a root.
        $parent = $span->parentId;

        if ($parent !== null && ! isset($capture->spans[$parent])) {
            $parent = null;
        }

        return [
            'id' => $span->id,
            'parent' => $parent,
            'kind' => $span->kind,
            'name' => $span->name,
            'color' => $this->color($span->color),
            's' => round($span->start ?? 0.0, 3),
            'e' => round($span->end ?? $capture->duration(), 3),
            'd' => [
                'meta' => $this->meta($span->meta),
                'origin' => $this->origin($span->origin),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rowNode(string $id, string $kind, string $section, array $row): array
    {
        $start = (float) $row['time'];
        $end = $start + (float) ($row['duration'] ?? 0);
        $sql = $row['sql_with_bindings'] ?? null;

        // The SQL with placeholders only adds something when bindings were filled in.
        $placeholders = null;

        if ($sql !== null && isset($row['sql']) && $row['sql'] !== $sql) {
            $placeholders = $row['sql'];
        }

        return [
            'id' => $id,
            'parent' => null,
            'kind' => $kind,
            'name' => $this->label($section, $row),
            's' => round($start, 3),
            'e' => round($end, 3),
            'd' => [
                'sql' => $sql,
                'placeholders' => $placeholders,
                'meta' => $this->meta(array_diff_key($row, array_flip(self::HIDDEN_KEYS))),
                'origin' => $this->origin($row['origin'] ?? null),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function label(string $section, array $row): string
    {
        $text = match ($section) {
            'queries' => (string) ($row['sql'] ?? ''),
            'cache' => strtoupper((string) ($row['type'] ?? '')).' '.($row['key'] ?? ''),
            'redis' => strtoupper((string) ($row['command'] ?? '')).' '.$this->redisParameters($row),
            'http' => ($row['method'] ?? '').' '.($row['url'] ?? ''),
            'filesystem' => ($row['op'] ?? '').' '.($row['disk'] ?? '').':'.($row['path'] ?? ''),
        };

        return mb_strimwidth(trim($text), 0, 300, '...');
    }

    /**
     * Redis parameters as text; anything that is not a scalar shows as "...".
     *
     * @param  array<string, mixed>  $row
     */
    private function redisParameters(array $row): string
    {
        $parameters = [];

        foreach ((array) ($row['parameters'] ?? []) as $value) {
            $parameters[] = is_scalar($value) ? (string) $value : '...';
        }

        return implode(' ', $parameters);
    }

    /**
     * Only colours the dashboard can draw safely: a SpanColor name or a hex value.
     */
    private function color(?string $color): ?string
    {
        if ($color === null) {
            return null;
        }

        if (SpanColor::tryFrom($color) !== null) {
            return $color;
        }

        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $color) === 1) {
            return $color;
        }

        return null;
    }

    /**
     * The deepest span that starts before and ends after the row.
     *
     * @param  array<string, int>  $depth
     */
    private function container(float $start, float $end, Capture $capture, array $depth): ?string
    {
        $best = null;

        foreach ($capture->spans as $span) {
            if (! $this->contains($span, $start, $end)) {
                continue;
            }

            // Later spans win ties: they are nested inside or follow the earlier one.
            if ($best === null || $depth[$span->id] >= $depth[$best]) {
                $best = $span->id;
            }
        }

        return $best;
    }

    /**
     * Small tolerances absorb clock jitter between span and row timestamps.
     */
    private function contains(Span $span, float $start, float $end): bool
    {
        if ($span->start === null) {
            return false;
        }

        if ($span->start > $start + 0.001) {
            return false;
        }

        // An open span runs until the end of the capture.
        return ($span->end ?? INF) >= $end - 0.05;
    }

    /**
     * Number of recorded ancestors; capped so a broken parent chain cannot loop forever.
     */
    private function depth(Span $span, Capture $capture): int
    {
        $depth = 0;
        $parent = $span->parentId;

        while ($parent !== null && isset($capture->spans[$parent]) && $depth < 100) {
            $depth++;
            $parent = $capture->spans[$parent]->parentId;
        }

        return $depth;
    }

    /**
     * Requests sent together by Http::pool() or Http::batch() hang under one
     * node spanning all of them.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @return array<string, array<string, mixed>>
     */
    private function pools(array $nodes, Capture $capture): array
    {
        $members = [];
        $failed = [];

        foreach ($capture->rows('http') as $index => $row) {
            $pool = $row['pool'] ?? null;
            $id = "http:{$index}";

            if (! is_int($pool)) {
                continue;
            }

            if (! isset($nodes[$id])) {
                continue;
            }

            $members[$pool][] = $id;
            $failed[$pool] = ($failed[$pool] ?? 0) + (($row['failed'] ?? false) ? 1 : 0);
        }

        foreach ($members as $pool => $ids) {
            $poolId = "http.pool:{$pool}";
            $rows = array_map(fn (string $id): array => $nodes[$id], $ids);
            $count = count($ids);

            $nodes[$poolId] = [
                'id' => $poolId,
                'parent' => $rows[0]['parent'],
                'kind' => 'http.pool',
                'name' => sprintf('pool #%d, %d %s', $pool, $count, $count === 1 ? 'request' : 'requests'),
                's' => min(array_column($rows, 's')),
                'e' => max(array_column($rows, 'e')),
                'd' => [
                    'meta' => ['requests' => $count, 'failed' => $failed[$pool]],
                    'origin' => $rows[0]['d']['origin'],
                ],
            ];

            foreach ($ids as $id) {
                $nodes[$id]['parent'] = $poolId;
            }
        }

        return $nodes;
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function tree(array $nodes): array
    {
        $children = [];

        foreach ($nodes as $id => $node) {
            $children[$node['parent'] ?? ''][] = $id;
        }

        $build = function (string $parent) use (&$build, $nodes, $children): array {
            $out = [];

            foreach ($children[$parent] ?? [] as $id) {
                $node = $nodes[$id];
                unset($node['parent']);
                $node['children'] = $build($id);
                $out[] = $node;
            }

            usort($out, fn (array $a, array $b): int => $a['s'] <=> $b['s']);

            return $out;
        };

        return $build('');
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function meta(array $meta): array
    {
        $links = [];

        foreach ($meta as $key => $value) {
            $value = $this->expand($key, $value);
            $meta[$key] = $value;
            $valueLinks = $this->linksFor($key, $value);

            if ($valueLinks !== []) {
                $links[$key] = $valueLinks;
            }
        }

        if ($links === []) {
            return $meta;
        }

        return $meta + ['_links' => $links];
    }

    /**
     * Throwables, stack frames and wrapped exceptions carry their own links
     * inline, so the dashboard can render them as they are.
     */
    private function expand(int|string $key, mixed $value): mixed
    {
        if ($this->isThrowable($value)) {
            return $this->throwable($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            return $value;
        }

        if ($key === 'frames') {
            return array_map(fn (mixed $frame): mixed => is_string($frame) ? $this->frame($frame) : $frame, $value);
        }

        if ($key === 'previous') {
            return $this->throwables($value);
        }

        return $value;
    }

    /**
     * The editor link of one meta value: a single link, links by rule or
     * dotted key, or [] when there is nothing to link.
     *
     * @return string|array<string, string>
     */
    private function linksFor(int|string $key, mixed $value): string|array
    {
        if ($key === 'rules' && is_array($value) && ! array_is_list($value)) {
            return $this->ruleLinks($value);
        }

        $link = $this->link($value);

        if ($link !== null) {
            return $link;
        }

        if (! is_array($value)) {
            return [];
        }

        // Frames and wrapped exceptions carry their links inline; origins get theirs from origin().
        if (in_array($key, ['frames', 'previous', 'origin'], true)) {
            return [];
        }

        return $this->nestedLinks($value);
    }

    /**
     * Editor links for class names and paths inside nested data, by dotted
     * key (payload.order => App\Models\Order#12). Large structures are only
     * looked through up to a point.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<string, string>
     */
    private function nestedLinks(array $value): array
    {
        $links = [];

        foreach (array_slice(Arr::dot($value), 0, 200, true) as $path => $item) {
            if (! is_string($item)) {
                continue;
            }

            $link = $this->link($item);

            if ($link !== null) {
                $links[(string) $path] = $link;
            }
        }

        return $links;
    }

    /**
     * Editor link for a value that names a class (optionally with a method)
     * or a file (optionally with a line).
     */
    public function link(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if ($value === '') {
            return null;
        }

        // Long values are text, not names; skip the regexes on them.
        if (strlen($value) > 500) {
            return null;
        }

        if (! $this->links->enabled()) {
            return null;
        }

        // A closure recorded as "Closure (path:line)" links to where it is written.
        if (preg_match('/^Closure \((.+:\d+)\)$/', $value, $closure) === 1) {
            return $this->link($closure[1]);
        }

        // A class, optionally followed by ->method, ::method, @method or #key (a model).
        if (preg_match('/^\\\\?([A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)+)(?:(?:->|::|@)(\w+)(?:\(\))?|#[\w-]+)?$/', $value, $match) === 1) {
            $method = $match[2] ?? '';

            return $this->links->class($match[1], $method === '' ? null : $method);
        }

        if (str_contains($value, '://')) {
            return null;
        }

        if (preg_match('/\s/', $value) === 1) {
            return null;
        }

        $path = str_replace('\\', '/', $value);

        // Compiled Blade views are not worth opening.
        if (str_contains($path, 'storage/framework/views/')) {
            return null;
        }

        if (preg_match('#^(.*/[^/\s]+\.[a-z]{2,5})(?::(\d+))?$#', $path, $match) === 1) {
            $line = isset($match[2]) ? (int) $match[2] : null;

            return $this->links->file($match[1], $line);
        }

        return null;
    }

    /**
     * Editor links for the file paths and class names mentioned in a sentence,
     * such as a warning, keyed by the text they replace.
     *
     * @return array<string, string>
     */
    public function textLinks(string $text): array
    {
        if (! $this->links->enabled()) {
            return [];
        }

        // URLs are not paths; leave them out so their host and path are not mistaken for files.
        $text = (string) preg_replace('#\b[a-z][\w+.-]*://\S+#i', ' ', $text);

        preg_match_all('#[\w.-]*(?:/[\w.-]+)+\.[a-z]{2,5}(?::\d+)?|\\\\?[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)+(?:(?:->|::|@)\w+(?:\(\))?)?#', $text, $matches);
        $links = [];

        foreach (array_unique($matches[0]) as $token) {
            $link = $this->link($token);

            if ($link !== null) {
                $links[$token] = $link;
            }
        }

        return $links;
    }

    /**
     * A section row with editor links on its origin and class/path values.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function row(array $row): array
    {
        if (is_array($row['origin'] ?? null)) {
            $row['origin'] = $this->origin($row['origin']);
        } elseif (is_string($row['origin'] ?? null)) {
            $row['origin'] = $this->originFromString($row['origin']);
        }

        return $this->meta($row);
    }

    /**
     * A recorded throwable with editor links on its class and file, and on
     * those of the exceptions it wraps.
     *
     * @param  array<string, mixed>  $throwable
     * @return array<string, mixed>
     */
    private function throwable(array $throwable): array
    {
        $throwable['link'] = $this->link($throwable['class']);
        $throwable['file_link'] = isset($throwable['file']) ? $this->link($throwable['file']) : null;

        if (is_array($throwable['previous'] ?? null)) {
            $throwable['previous'] = $this->throwables($throwable['previous']);
        }

        return $throwable;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function throwables(array $values): array
    {
        return array_map(fn (mixed $one): mixed => $this->isThrowable($one) ? $this->throwable($one) : $one, $values);
    }

    /**
     * Editor links for rule classes ("App\Rules\Slug {...}") and closures
     * ("Closure (path:line)") in validation rules, keyed by the rule.
     *
     * @param  array<array-key, mixed>  $rules  field => "rule|rule"
     * @return array<string, string>
     */
    private function ruleLinks(array $rules): array
    {
        $links = [];

        foreach ($rules as $field) {
            if (! is_string($field)) {
                continue;
            }

            foreach (explode('|', $field) as $rule) {
                $link = $this->ruleLink($rule);

                if ($link !== null) {
                    $links[$rule] = $link;
                }
            }
        }

        return $links;
    }

    /**
     * Only rules naming a class or a file can link; "required" or "max:5" cannot.
     */
    private function ruleLink(string $rule): ?string
    {
        if (preg_match('/^Closure \((.+)\)$/', $rule, $match) === 1) {
            $target = $match[1];
        } else {
            $target = explode(' ', $rule, 2)[0];
        }

        if (str_contains($target, '\\')) {
            return $this->link($target);
        }

        if (str_contains($target, '.php')) {
            return $this->link($target);
        }

        return null;
    }

    /**
     * A stack frame recorded as "file:line call", split up and linked.
     *
     * @return array{file: string, call: string|null, link: string|null, call_link: string|null}
     */
    private function frame(string $frame): array
    {
        [$where, $call] = array_pad(explode(' ', $frame, 2), 2, null);

        return [
            'file' => $where,
            'call' => $call,
            'link' => $this->link($where),
            'call_link' => $call === null ? null : $this->link($call),
        ];
    }

    /**
     * @phpstan-assert-if-true array{class: string, message: mixed} $value
     */
    private function isThrowable(mixed $value): bool
    {
        return is_array($value) && is_string($value['class'] ?? null) && array_key_exists('message', $value);
    }

    /**
     * Origin given as "file:line", as the compact row and query views give it.
     *
     * @return array<string, mixed>|null
     */
    public function originFromString(string $origin): ?array
    {
        [$where, $via] = array_pad(explode(' via ', $origin, 2), 2, null);
        $via = $via === null ? null : ['call' => $via];

        if (preg_match('/^(.*):(\d+|\?)$/', $where, $match) !== 1) {
            return $this->origin(['file' => $where, 'via' => $via]);
        }

        return $this->origin(['file' => $match[1], 'line' => is_numeric($match[2]) ? (int) $match[2] : null, 'via' => $via]);
    }

    /**
     * @param  array<string, mixed>|null  $origin
     * @return array<string, mixed>|null
     */
    public function origin(?array $origin): ?array
    {
        if ($origin === null || ! isset($origin['file'])) {
            return null;
        }

        $line = isset($origin['line']) ? (int) $origin['line'] : null;
        $via = is_array($origin['via'] ?? null) ? $this->originFrame($origin['via']) : null;
        $frames = array_map($this->originFrame(...), array_values((array) ($origin['frames'] ?? [])));

        return [
            'file' => $origin['file'],
            'line' => $line,
            'call' => $origin['call'] ?? null,
            'call_link' => $this->link($origin['call'] ?? null),
            'vendor' => $origin['vendor'] ?? false,
            'via' => $via,
            'link' => $this->links->file((string) $origin['file'], $line),
            'frames' => $frames,
        ];
    }

    /**
     * One frame of an origin's call chain (or its "via" frame), linked.
     *
     * @param  array<string, mixed>  $frame
     * @return array{file: mixed, line: mixed, call: mixed, link: string|null, call_link: string|null}
     */
    private function originFrame(array $frame): array
    {
        $link = null;

        if (isset($frame['file'])) {
            $line = isset($frame['line']) ? (int) $frame['line'] : null;
            $link = $this->links->file((string) $frame['file'], $line);
        }

        return [
            'file' => $frame['file'] ?? null,
            'line' => $frame['line'] ?? null,
            'call' => $frame['call'] ?? null,
            'link' => $link,
            'call_link' => $this->link($frame['call'] ?? null),
        ];
    }
}
