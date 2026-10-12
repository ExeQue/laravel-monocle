<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\GraphQL;

use ExeQue\Monocle\Contracts\FormatsCapture;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Data\Card;
use ExeQue\Monocle\Data\Formatted;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\RequestLens;
use GraphQL\Language\Parser;
use GraphQL\Utils\AST;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * One card per GraphQL operation in a request, a batch included: the query,
 * its variables, and the data and errors it answered with.
 */
final readonly class GraphQLCapture implements FormatsCapture, RequiresPackages
{
    public function __construct(
        private Eyepiece $eyepiece
    ) {
    }

    public static function packages(): array
    {
        return ['webonyx/graphql-php'];
    }

    public function format(Request $request, Response $response): Formatted
    {
        $formatted = $this->cards(Formatted::make(), $request, $response);

        // Each query printed one field per line, with values written into it masked, in the payload too.
        foreach ($this->queries($request) as $key => $query) {
            $formatted = $formatted->field($key, 'graphql', QueryFormatter::pretty($query))->replace($key, $query);
        }

        return $formatted;
    }

    /**
     * Each operation's query, masked, by its payload key: `query`, or
     * `0.query`, `1.query`... in a batch.
     *
     * @return array<string, string>
     */
    private function queries(Request $request): array
    {
        $sent = $request->input();

        return collect(array_is_list($sent) ? $sent : ['' => $sent])
            ->filter(fn (mixed $operation): bool => is_string($operation['query'] ?? null))
            ->mapWithKeys(fn (array $operation, int|string $index): array => [ltrim($index.'.query', '.') => $this->mask($operation['query'])])
            ->all();
    }

    /**
     * One card per operation; none when the response is not a GraphQL result.
     */
    private function cards(Formatted $formatted, Request $request, Response $response): Formatted
    {
        $body = json_decode((string) $response->getContent(), true);

        if (! is_array($body)) {
            return $formatted;
        }

        // A multipart upload sends its operations as a JSON field.
        $sent = is_string($request->input('operations'))
            ? json_decode($request->input('operations'), true)
            : $request->input();

        $batch = array_is_list($body) && is_array($sent) && array_is_list($sent);
        $results = collect($batch ? $body : [$body]);
        $operations = $batch ? $sent : [$sent];

        if (! $results->every(fn (mixed $result): bool => is_array($result) && (array_key_exists('data', $result) || array_key_exists('errors', $result)))) {
            return $formatted;
        }

        return $results->reduce(fn (Formatted $carded, array $result, int $index): Formatted => $this->card($carded, (array) ($operations[$index] ?? []), $result), $formatted);
    }

    /**
     * @param  array<string, mixed>  $operation  as the request sent it
     * @param  array<string, mixed>  $result  as the response returned it
     */
    private function card(Formatted $formatted, array $operation, array $result): Formatted
    {
        $query = is_string($operation['query'] ?? null) ? $operation['query'] : null;
        $name = is_string($operation['operationName'] ?? null) ? $operation['operationName'] : null;
        $definition = null;

        try {
            $definition = $query === null ? null : AST::getOperationAST(Parser::parse($query, ['noLocation' => true]), $name);
        } catch (Throwable) {
            // A query that does not parse is shown as it was sent.
        }

        $errors = collect($result['errors'] ?? [])->values()->map($this->error(...));
        $card = Card::titled(trim(($definition->operation ?? 'GraphQL').' '.($name ?? $definition?->name?->value)))
            ->subtitle($errors->isEmpty() ? null : $errors->count().' '.Str::plural('error', $errors->count()));

        if ($query !== null) {
            $card = $card->item('query', QueryFormatter::pretty($this->mask($query)), 'graphql');
        }

        $items = collect([
            'variables' => $operation['variables'] ?? null,
            'data' => $result['data'] ?? null,
            'errors' => $errors->all(),
            'extensions' => $result['extensions'] ?? null,
        ])->reject(fn (mixed $value): bool => $value === null || $value === []);

        return $formatted->card($card->items($items));
    }

    private function mask(string $query): string
    {
        return QueryMasker::mask($query, $this->eyepiece->masker(RequestLens::class));
    }

    /**
     * An error as one flat row: debug traces are left to the raw body, so
     * the table stays readable.
     *
     * @return array<string, string|null>
     */
    private function error(mixed $error): array
    {
        $error = (array) $error;
        $extensions = collect($error['extensions'] ?? []);
        $file = $extensions->get('file');
        $rest = $extensions->except(['debugMessage', 'file', 'line', 'trace']);

        return collect([
            'message' => (string) ($error['message'] ?? ''),
            'path' => collect($error['path'] ?? [])->implode('.'),
            'location' => collect($error['locations'] ?? [])->map(fn (mixed $at): string => ($at['line'] ?? '?').':'.($at['column'] ?? '?'))->implode(', '),
            'debug' => $extensions->get('debugMessage'),
            'file' => is_string($file) ? $file.($extensions->has('line') ? ':'.$extensions->get('line') : '') : null,
            'extensions' => $rest->isEmpty() ? null : $rest->toJson(JSON_UNESCAPED_SLASHES),
        ])->reject(fn (?string $value): bool => $value === null || $value === '')->all();
    }
}
