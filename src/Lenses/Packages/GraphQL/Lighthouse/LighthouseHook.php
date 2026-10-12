<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\GraphQL\Lighthouse;

use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLHook;
use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLLens;
use GraphQL\Server\OperationParams;
use GraphQL\Utils\AST;
use Nuwave\Lighthouse\Events\EndExecution;
use Nuwave\Lighthouse\Events\EndOperationOrOperations;
use Nuwave\Lighthouse\Events\StartExecution;
use Nuwave\Lighthouse\Events\StartOperationOrOperations;

/**
 * nuwave/lighthouse operations. Each one runs between StartExecution and
 * EndExecution. One that fails to parse never starts, and one that fails
 * validation never ends, so those are recorded from the result of the
 * request's operations instead.
 */
final class LighthouseHook implements GraphQLHook, RequiresPackages, Resettable
{
    private GraphQLLens $lens;

    /** @var list<array<string, mixed>> Operations started and not yet ended. */
    private array $open = [];

    /** The request's one operation, or null for a batch. */
    private ?OperationParams $single = null;

    /** Rows pushed since the request started its operations. */
    private int $pushed = 0;

    private float $operationsStarted = 0.0;

    public function __construct(
        private readonly Eyepiece $eyepiece,
        private readonly SchemaFiles $files,
    ) {
    }

    public static function packages(): array
    {
        return ['nuwave/lighthouse'];
    }

    public function hook(GraphQLLens $lens): void
    {
        $this->lens = $lens;

        $this->eyepiece->listen(StartOperationOrOperations::class, function (StartOperationOrOperations $event): void {
            $this->reset();
            $this->single = $event->operationOrOperations instanceof OperationParams ? $event->operationOrOperations : null;
            $this->operationsStarted = $this->eyepiece->now();
        });

        $this->eyepiece->listen(StartExecution::class, function (StartExecution $event): void {
            $operation = $this->eyepiece->recording() ? AST::getOperationAST($event->query, $event->operationName) : null;
            $fields = $operation === null ? [] : app(RootResolvers::class)->of($event->schema, $event->query, $operation);
            // Lighthouse serves one schema; its root file names it.
            $pending = $this->lens->start('lighthouse', $this->files->root(), $event->query, $event->operationName, $event->variables, ['fields' => $fields ?: null]);

            if ($pending !== null) {
                $this->open[] = $pending;
            }
        });

        $this->eyepiece->listen(EndExecution::class, function (EndExecution $event): void {
            $pending = array_pop($this->open);

            if ($pending !== null) {
                $this->lens->finish($pending, $event->result->errors);
                $this->pushed++;
            }
        });

        $this->eyepiece->listen(EndOperationOrOperations::class, function (EndOperationOrOperations $event): void {
            // Only the one operation of a request is known to own the errors of the result.
            $errors = $this->single === null ? null : array_values($event->resultOrResults['errors'] ?? []);

            foreach ($this->open as $pending) {
                $this->lens->finish($pending, $errors);
                $this->pushed++;
            }

            if ($this->single !== null && $this->pushed === 0) {
                $this->lens->failed('lighthouse', $this->files->root(), $this->single->query, $this->single->operation, $this->single->variables, $errors ?? [], $this->operationsStarted);
            }

            $this->reset();
        });
    }

    public function reset(): void
    {
        $this->open = [];
        $this->single = null;
        $this->pushed = 0;
    }
}
