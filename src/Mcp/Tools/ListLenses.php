<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Mcp\Tools;

use ExeQue\Monocle\LensRegistry;
use ExeQue\Monocle\Mcp\Tools\Concerns\RespondsWithJson;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** @internal */
#[IsReadOnly]
#[Name('monocle-lenses')]
#[Description('List the lenses enabled in this application: each section key you can pass to monocle-show and monocle-rows, with when to look at it and what it records.')]
final class ListLenses extends Tool
{
    use RespondsWithJson;

    public function __construct(
        private readonly LensRegistry $registry
    ) {
    }

    public function handle(Request $request): Response
    {
        $advice = $this->registry->advice();

        $lenses = collect($this->registry->descriptions())
            ->map(fn (string $description, int|string $key): array => ['section' => $key, 'when' => $advice[$key] ?? null, 'description' => $description])
            ->values();

        return $this->json(['lenses' => $lenses->all()]);
    }
}
