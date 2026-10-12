<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\GraphQL;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\Exceptions\InvalidGraphQLHookException;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\GraphQL\Lighthouse\LighthouseHook;
use ExeQue\Monocle\Lenses\Packages\GraphQL\Rebing\RebingHook;
use ExeQue\Monocle\LensRegistry;
use ExeQue\Monocle\Support\Normalizer;
use ExeQue\Monocle\Support\ThrowableChain;
use GraphQL\Error\Error;
use GraphQL\Error\SyntaxError;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Printer;
use GraphQL\Utils\AST;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Arr;

/**
 * GraphQL operations served by nuwave/lighthouse, rebing/graphql-laravel or
 * both: one row per operation with its query, variables and errors. A
 * GraphQL endpoint answers 200 even when an operation fails, so the errors
 * are otherwise easy to miss.
 */
final class GraphQLLens implements Advises, Configurable, Lens, Presentable, RequiresPackages, Resettable, Summarizable
{
    /** @var list<class-string<GraphQLHook>> The servers recorded, through their hooks. */
    private array $hooks = [LighthouseHook::class, RebingHook::class];

    /** @var list<GraphQLHook> */
    private array $booted = [];

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'graphql';
    }

    public static function packages(): array
    {
        return ['webonyx/graphql-php'];
    }

    public function description(): string
    {
        return 'GraphQL operations served by nuwave/lighthouse or rebing/graphql-laravel: server (lighthouse or rebing), schema (rebing), type (query, mutation, subscription), operation name, status (ok or failed), errors (message, path, locations and the exception behind it), query, variables, fields (each root field the operation selected with its resolver as Class@method and, on Lighthouse, the schema file and line that defines it) and duration. Each operation is a graphql span in the timeline, so the queries and calls its resolvers made sit under it.';
    }

    public function advice(): string
    {
        return 'Look here for GraphQL requests: which operation ran, with what variables, and the errors it returned behind a 200 response.';
    }

    public function configure(array $options): void
    {
        $this->hooks = array_values(Arr::from($options['hooks'] ?? $this->hooks));
    }

    /**
     * Hooks for servers whose package is not installed are left out.
     */
    public function boot(Application $app): void
    {
        foreach ($this->hooks as $class) {
            if (! LensRegistry::available($class)) {
                continue;
            }

            $hook = $app->make($class);

            if (! $hook instanceof GraphQLHook) {
                throw InvalidGraphQLHookException::forClass($class);
            }

            $hook->hook($this);
            $this->booted[] = $hook;
        }
    }

    public function reset(): void
    {
        foreach ($this->booted as $hook) {
            if ($hook instanceof Resettable) {
                $hook->reset();
            }
        }
    }

    /**
     * Start timing an operation. The query is a parsed document when the
     * server has one, else its source, which is parsed here.
     *
     * @param  array<string, mixed>|null  $variables
     * @param  array<string, mixed>  $extra  more fields for the row, such as the root fields and their resolvers
     * @return array<string, mixed>|null null while nothing is recorded
     */
    public function start(string $server, ?string $schema, DocumentNode|string|null $query, ?string $operation, ?array $variables, array $extra = []): ?array
    {
        if (! $this->eyepiece->recording()) {
            return null;
        }

        $document = $query instanceof DocumentNode ? $query : self::parse($query);
        $definition = $document === null ? null : AST::getOperationAST($document, $operation);
        $operation ??= $definition?->name?->value;
        $type = $definition?->operation;

        return [
            'server' => $server,
            'schema' => $schema,
            'type' => $type,
            'operation' => $operation,
            'query' => $query instanceof DocumentNode ? ($query->loc?->source?->body ?? Printer::doPrint($query)) : $query,
            'variables' => $this->eyepiece->masker(self::class)->inputs(Normalizer::normalize($variables ?? [])),
            ...$extra,
            'time' => $this->eyepiece->now(),
            'span' => $this->eyepiece->span(trim(($type ?? 'graphql').' '.$operation), 'graphql')->start(array_filter([
                'server' => $server,
                'schema' => $schema,
                'operation' => $operation,
            ])),
        ];
    }

    /**
     * Push the row of an operation started with start().
     *
     * @param  array<string, mixed>  $pending
     * @param  list<Error|array<string, mixed>>|null  $errors  null when the server did not say
     * @param  array<string, mixed>  $fill  fields learnt only once it ran, kept where start() knew none
     */
    public function finish(array $pending, ?array $errors, array $fill = []): void
    {
        $span = $pending['span'];
        unset($pending['span']);

        foreach ($fill as $field => $value) {
            $pending[$field] ??= $value;
        }

        $span->stop(['type' => $pending['type'], 'errors' => $errors === null ? null : count($errors)]);

        $this->eyepiece->push('graphql', [
            ...$pending,
            'query' => $this->maskQuery($pending['query']),
            'status' => $errors === [] ? 'ok' : 'failed',
            'errors' => $errors === null ? null : array_map(self::error(...), $errors),
            'duration' => $span->duration(),
        ], origin: false);
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = collect($capture->rows('graphql'));

        if ($rows->isEmpty()) {
            return Summary::make();
        }

        $errors = $rows->where('status', 'failed')->take(5)->map(function (array $row): string {
            $more = count($row['errors'] ?? []) - 1;

            return sprintf(
                'GraphQL %s %s failed%s',
                $row['type'] ?? 'operation',
                $row['operation'] ?? '(anonymous)',
                isset($row['errors'][0]) ? ': '.$row['errors'][0]['message'].($more > 0 ? " (+{$more} more)" : '') : '.',
            );
        });

        return Summary::make()
            ->total('graphql_ms', round((float) $rows->sum('duration'), 3), 'spent', Unit::Milliseconds)
            ->total('graphql_operations', $rows->count(), 'operations')
            ->errors($errors->values());
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.graphql')
            ->label('GraphQL')
            ->columns(['type', 'operation', 'status', 'schema', 'server', 'duration'])
            ->detailsOnly(['query', 'variables', 'fields', 'errors'])
            ->details('monocle::details.graphql')
            ->format(['operation' => 'code', 'status' => 'status'])
            ->groupBy(['operation' => 'by operation']);
    }

    /**
     * Push the row of an operation that failed before it started, such as
     * one that did not parse.
     *
     * @param  array<string, mixed>|null  $variables
     * @param  list<Error|array<string, mixed>>  $errors
     * @param  float  $started  when the server took it on, from Eyepiece::now()
     */
    public function failed(string $server, ?string $schema, ?string $query, ?string $operation, ?array $variables, array $errors, float $started): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $this->eyepiece->push('graphql', [
            'server' => $server,
            'schema' => $schema,
            'type' => null,
            'operation' => $operation,
            'query' => $this->maskQuery($query),
            'variables' => $this->eyepiece->masker(self::class)->inputs(Normalizer::normalize($variables ?? [])),
            'time' => $started,
            'status' => 'failed',
            'errors' => array_map(self::error(...), $errors),
            'duration' => round($this->eyepiece->now() - $started, 3),
        ], origin: false);
    }

    /**
     * A query sent as text, parsed; null when there is none or it does not parse.
     */
    private static function parse(?string $query): ?DocumentNode
    {
        try {
            return $query === null ? null : Parser::parse($query, ['noLocation' => true]);
        } catch (SyntaxError) {
            return null;
        }
    }

    /**
     * The query with values written into it masked like input, such as
     * login(password: "...").
     */
    private function maskQuery(?string $query): ?string
    {
        return $query === null ? null : QueryMasker::mask($query, $this->eyepiece->masker(self::class));
    }

    /**
     * An error as the server holds it, or as it sent it out.
     *
     * @param  Error|array<string, mixed>  $error
     * @return array<string, mixed>
     */
    private static function error(Error|array $error): array
    {
        if (is_array($error)) {
            return [
                'message' => (string) ($error['message'] ?? ''),
                'path' => $error['path'] ?? null,
                'locations' => $error['locations'] ?? [],
                'exception' => null,
            ];
        }

        $previous = $error->getPrevious();

        return [
            'message' => $error->getMessage(),
            'path' => $error->getPath(),
            'locations' => collect($error->getLocations())->map(fn ($location): array => ['line' => $location->line, 'column' => $location->column])->all(),
            // The exception a resolver threw, which the response hides behind "Internal server error".
            'exception' => $previous === null ? null : ThrowableChain::describe($previous),
        ];
    }
}
