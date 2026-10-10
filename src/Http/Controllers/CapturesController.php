<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Http\Controllers;

use ExeQue\Monocle\Dashboard\TimelinePresenter;
use ExeQue\Monocle\Editor\EditorLinks;
use ExeQue\Monocle\Reader\CaptureReader;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * JSON for the dashboard, built on the same Viewfinder the MCP tools and
 * artisan commands use.
 */
final readonly class CapturesController
{
    public function __construct(private Viewfinder $viewfinder)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->viewfinder->list($request->query()));
    }

    public function show(string $id, CaptureReader $captures, TimelinePresenter $timeline, EditorLinks $links): JsonResponse
    {
        $capture = $captures->find($id);

        abort_if($capture === null, 404);

        $result = $this->viewfinder->show(['id' => $capture->id, 'sections' => ['summary']]);

        return response()->json($result + [
            'timeline' => $timeline->present($capture),
            'editor' => $links->enabled(),
        ]);
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
