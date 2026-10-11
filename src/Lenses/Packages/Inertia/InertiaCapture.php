<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Inertia;

use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\ResponseCapture;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An Inertia page: the component, its props and, for partial reloads, which
 * props were asked for. Reads both the JSON of an Inertia visit and the page
 * embedded in the HTML of a first visit; other HTML is left alone.
 */
final readonly class InertiaCapture implements RequiresPackages, ResponseCapture
{
    public static function packages(): array
    {
        return ['inertiajs/inertia-laravel'];
    }

    public function __construct(
        private Eyepiece $eyepiece
    ) {
    }

    public function capture(Request $request, Response $response): ?array
    {
        $page = $this->page((string) $response->getContent());

        if (! is_array($page)) {
            return null;
        }

        if (! isset($page['component'])) {
            return null;
        }

        $partial = array_filter([
            'component' => $request->headers->get('X-Inertia-Partial-Component'),
            'only' => $this->list($request->headers->get('X-Inertia-Partial-Data')),
            'except' => $this->list($request->headers->get('X-Inertia-Partial-Except')),
        ]);

        return [[
            'title' => (string) $page['component'],
            'subtitle' => isset($page['url']) ? (string) $page['url'] : null,
            'items' => array_filter([
                'visit' => $request->headers->has('X-Inertia') ? 'inertia' : 'first load',
                'version' => $page['version'] ?? null,
                'partial_reload' => $partial === [] ? null : $partial,
                'props' => $this->eyepiece->masker()->inputs((array) ($page['props'] ?? [])),
                'deferred_props' => $page['deferredProps'] ?? null,
                'merge_props' => $page['mergeProps'] ?? null,
                'encrypt_history' => ($page['encryptHistory'] ?? false) ?: null,
                'clear_history' => ($page['clearHistory'] ?? false) ?: null,
            ], fn (mixed $value): bool => $value !== null && $value !== []),
        ]];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function page(string $content): ?array
    {
        $trimmed = ltrim($content);

        if (str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);

            return is_array($decoded) ? $decoded : null;
        }

        if (! str_contains($content, 'data-page')) {
            return null;
        }

        // Newer Inertia puts the page in a script tag, older versions in an escaped data-page attribute.
        if (preg_match('#<script[^>]*data-page[^>]*>(.*?)</script>#s', $content, $match) === 1) {
            $decoded = json_decode($match[1], true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (preg_match('#data-page="([^"]*)"#', $content, $match) === 1) {
            $decoded = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function list(?string $header): array
    {
        if ($header === null) {
            return [];
        }

        if ($header === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $header))));
    }
}
