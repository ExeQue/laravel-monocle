<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Http\Controllers;

use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Dashboard\TabList;
use ExeQue\Monocle\Dashboard\TimelinePresenter;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Editor\EditorLinks;
use ExeQue\Monocle\LensRegistry;
use ExeQue\Monocle\Reader\CaptureReader;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * JSON for the dashboard, built on the same Viewfinder the MCP tools and
 * artisan commands use.
 *
 * @internal
 */
final readonly class CapturesController
{
    public function __construct(
        private Viewfinder $viewfinder,
        private LensRegistry $registry,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->viewfinder->list($request->query()));
    }

    public function show(string $id, CaptureReader $captures, TimelinePresenter $timeline, EditorLinks $links, TabList $tabs): JsonResponse
    {
        $capture = $captures->find($id);

        abort_if($capture === null, 404);

        $result = $this->viewfinder->show(['id' => $capture->id, 'sections' => ['summary']]);
        $summary = $result['summary'] ?? [];
        $presentations = array_map(fn (Presentation $presentation): array => $presentation->toArray(), $this->registry->presentations());

        return response()->json($result + [
            'timeline' => $timeline->present($capture),
            'warning_links' => array_map($timeline->textLinks(...), $summary['warnings'] ?? []),
            'error_links' => array_map($timeline->textLinks(...), $summary['errors'] ?? []),
            'editor' => $links->enabled(),
            'presentations' => $presentations,
            'tabs' => $tabs->for($capture, $this->tabOrder(), $summary['problems'] ?? []),
            'children' => $this->children($captures, $capture->id),
            'context' => $this->context($capture, $timeline),
        ]);
    }

    /**
     * The index fields the dashboard shows for each child capture.
     *
     * @return list<array<string, mixed>>
     */
    private function children(CaptureReader $captures, string $id): array
    {
        $fields = array_flip(['id', 'type', 'name', 'status', 'has_errors', 'job_id', 'started_at']);

        return array_map(
            fn (array $child): array => array_intersect_key($child, $fields),
            $captures->children($id),
        );
    }

    /**
     * Where the capture came from: the URL to open again, the route or command, with editor links.
     *
     * @return array{url: mixed, route: mixed, action: mixed, action_link: string|null, command_class: mixed, command_link: string|null}
     */
    private function context(Capture $capture, TimelinePresenter $timeline): array
    {
        $request = $capture->section('request');
        $route = $request['route'] ?? [];
        $command = $capture->section('command');

        // Only a GET request can be opened again from a link.
        $url = ($request['method'] ?? null) === 'GET' ? ($request['url'] ?? null) : null;

        return [
            'url' => $url,
            'route' => $route['name'] ?? null,
            'action' => $route['action'] ?? null,
            'action_link' => $timeline->link($route['action'] ?? null),
            'command_class' => $command['class'] ?? null,
            'command_link' => $timeline->link($command['class'] ?? null),
        ];
    }

    /**
     * The section keys in the order monocle.dashboard.tabs lists them; lens
     * classes stand for the section they record.
     *
     * @return list<string>
     */
    private function tabOrder(): array
    {
        $keys = [];

        foreach ($this->registry->booted() as $lens) {
            $keys[$lens::class] = $lens->key();
        }

        return array_values(array_map(
            fn (mixed $tab): string => $keys[$tab] ?? (string) $tab,
            (array) config('monocle.dashboard.tabs', []),
        ));
    }

    /**
     * A row of a Presentable lens's section rendered by the lens's details view.
     */
    public function details(string $id, string $section, int $n, CaptureReader $captures): JsonResponse
    {
        $view = ($this->registry->presentations()[$section] ?? null)?->detailsView;

        abort_if($view === null, 404);

        $capture = $captures->find($id);

        abort_if($capture === null, 404);

        $row = $capture->rows($section)[$n - 1] ?? null;

        abort_if($row === null, 404);

        return response()->json(['html' => view($view, ['row' => $row, 'capture' => $capture])->render()]);
    }

    public function rows(Request $request, string $id, string $section, TimelinePresenter $presenter): JsonResponse
    {
        $result = $this->viewfinder->rows(['id' => $id, 'section' => $section] + $request->query());

        if (isset($result['rows'])) {
            $result['rows'] = array_map($presenter->row(...), $result['rows']);
        }

        if (isset($result['data']) && is_array($result['data'])) {
            $result['links'] = array_filter(array_map($presenter->link(...), Arr::dot($result['data'])));
        }

        return $this->respond($result);
    }

    public function queries(Request $request, string $id, TimelinePresenter $presenter): JsonResponse
    {
        $result = $this->viewfinder->queries(['id' => $id] + $request->query());

        foreach ($result['rows'] ?? [] as $index => $group) {
            if (is_string($group['origin'] ?? null)) {
                $result['rows'][$index]['origin'] = $presenter->originFromString($group['origin']);
            }
        }

        return $this->respond($result);
    }

    public function trace(string $id): JsonResponse
    {
        return $this->respond($this->viewfinder->trace(['id' => $id]));
    }

    public function destroy(): JsonResponse
    {
        return response()->json($this->viewfinder->clear());
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function respond(array $result): JsonResponse
    {
        return response()->json($result, isset($result['error']) ? 404 : 200);
    }
}
