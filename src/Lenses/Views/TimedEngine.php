<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Views;

use Closure;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\View\Engine;

/**
 * Wraps a view engine so every render - including nested includes and
 * components - becomes a `view` span and a row in the views section.
 *
 * @internal
 */
final class TimedEngine implements Engine
{
    private const array INTERNAL_KEYS = ['__env', 'app', 'errors', '__currentLoopData', '__laravel_slots', '__data', '__path'];

    public function __construct(
        private readonly Engine $inner,
        private readonly string $engine,
        private readonly Eyepiece $eyepiece,
        /** @var Closure(): list<string> */
        private readonly Closure $viewPaths,
        private readonly string $basePath,
        private readonly bool $data = false,
    ) {
    }

    public function get($path, array $data = []): string
    {
        if (! $this->eyepiece->recording()) {
            return $this->inner->get($path, $data);
        }

        $name = $this->viewName((string) $path, $data);
        $relative = $this->relative((string) $path);
        $keys = $this->dataKeys($data);

        $span = $this->eyepiece->span($name, 'view')->start([
            'path' => $relative,
            'engine' => $this->engine,
            'data_keys' => $keys,
        ]);

        try {
            return $this->inner->get($path, $data);
        } finally {
            $span->stop();

            $row = [
                'view' => $name,
                'path' => $relative,
                'engine' => $this->engine,
                'duration' => $span->duration(),
                'data_keys' => $keys,
                'origin' => $span->origin,
            ];

            if ($this->data) {
                $row['data'] = array_diff_key($data, array_flip(self::INTERNAL_KEYS));
            }

            $this->eyepiece->push('views', $row);
        }
    }

    public function inner(): Engine
    {
        return $this->inner;
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }

    /**
     * The keys of the data the view was given, without Laravel's own and the
     * underscored ones.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<array-key>
     */
    private function dataKeys(array $data): array
    {
        $keys = array_diff(array_keys($data), self::INTERNAL_KEYS);
        $keys = array_filter($keys, fn (int|string $key): bool => ! str_starts_with((string) $key, '_'));

        return array_values($keys);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function viewName(string $path, array $data): string
    {
        foreach (($this->viewPaths)() as $views) {
            $views = rtrim((string) $views, '/').'/';

            if (! str_starts_with($path, $views)) {
                continue;
            }

            // resources/views/users/show.blade.php is users.show.
            $file = $this->stripExtension(substr($path, strlen($views)));

            return str_replace('/', '.', $file);
        }

        $name = $this->stripExtension(basename($path));

        // Inline templates (component render() strings, Blade::render()) are written
        // to a hash-named file; name them after what rendered them instead.
        if (preg_match('/^[0-9a-f]{32,40}$/', $name) !== 1) {
            return $name;
        }

        $component = $data['componentName'] ?? null;

        if (is_string($component) && $component !== '') {
            return '<x-'.$component.'>';
        }

        return 'inline:'.substr($name, 0, 8);
    }

    private function stripExtension(string $name): string
    {
        return preg_replace('/\.(blade\.php|php|html|css)$/', '', $name) ?? $name;
    }

    private function relative(string $path): string
    {
        $base = rtrim($this->basePath, '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
