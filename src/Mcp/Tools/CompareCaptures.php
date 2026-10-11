<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Mcp\Tools;

use ExeQue\Monocle\Mcp\Tools\Concerns\RespondsWithJson;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('monocle-compare')]
#[Description('Compare two captures: deltas of every numeric total, time per phase and category, query shapes that appeared or disappeared, and warnings unique to each side. Use it to verify that a change made things better: label the first run (X-Monocle-Label or Monocle::label()), change the code, run again, compare.')]
final class CompareCaptures extends Tool
{
    use RespondsWithJson;

    public function __construct(
        private readonly Viewfinder $viewfinder
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'a' => $schema->string()->required()->description('Capture id or label of the baseline.'),
            'b' => $schema->string()->description('Capture id or label to compare against. Defaults to "latest".'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->json($this->viewfinder->compare($request->all()));
    }
}
