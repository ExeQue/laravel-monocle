<!-- Part of the Monocle documentation - see README.md -->

# Writing a GraphQL hook

The [GraphQL lens](graphql.md) records operations through hooks, one per GraphQL server. Monocle comes with a hook for Lighthouse (`LighthouseHook`) and one for rebing/graphql-laravel (`RebingHook`). For any other server - webonyx/graphql-php's `StandardServer`, a controller of your own that calls `GraphQL::executeQuery()`, another package - write a hook and add it to the lens's `hooks` option. Its operations then show on the GraphQL tab like the built-in ones: one row and one span per operation, masked variables and query values, errors in the summary.

## What a hook does

A hook is a class implementing `ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLHook`:

```php
interface GraphQLHook
{
    public function hook(GraphQLLens $lens): void;
}
```

Monocle resolves each hook from the container when it boots, so the constructor can take what it needs, and calls `hook()` once with the lens. In `hook()` the hook finds a way to see operations start and end - an event the server fires, middleware it runs, a class it can wrap - and tells the lens about them:

| Call | When | Returns |
|---|---|---|
| `$lens->start(string $server, ?string $schema, DocumentNode\|string\|null $query, ?string $operation, ?array $variables, array $extra = [])` | an operation starts | what `finish()` needs, or `null` while nothing is being recorded |
| `$lens->finish(array $started, ?array $errors)` | the operation ended | nothing |
| `$lens->failed(string $server, ?string $schema, ?string $query, ?string $operation, ?array $variables, array $errors, float $started)` | an operation failed before it could start, such as one that did not parse | nothing |

Their arguments:

| Argument | Meaning |
|---|---|
| `$server` | the name the rows carry under `server`, such as `standard`. Pick one per server and keep it. |
| `$schema` | the name the rows carry under `schema`: a schema name, a file, or `null` when the server has one schema |
| `$query` | the parsed document when the server has one, else the query as it was sent. Text is parsed by the lens; the operation type and name are read from it. |
| `$operation` | the `operationName` the request asked for, or `null`. Without one, the name is read from the query. |
| `$variables` | the variables as sent. The lens masks them like request input. |
| `$extra` | more fields for the row. `fields` takes a list of `['field' => ..., 'resolver' => 'Class@method', 'definition' => 'path:line' or null]`, shown as the Fields table of the folded-out row. |
| `$errors` | the errors of the result: webonyx `Error` objects, or errors as the response sent them (arrays with `message`, `path`, `locations`). An empty list means the operation went well. For `finish()`, `null` means the server did not say which errors were this operation's. |
| `$started` (`failed()`) | when the server took the operation on, from `Eyepiece::now()`, so the row gets a duration |

The lens does the rest: the row, the `graphql` span on the Timeline, masking of variables and of values written into the query, the status, the summary and the warnings. `start()` and `failed()` record nothing while no capture is being recorded, so a hook can call them without checking.

A hook can also implement:

- `ExeQue\Monocle\Contracts\Lenses\RequiresPackages` - `packages()` names the Composer packages the server comes in. The hook is skipped where one is not installed, so you can list it for every environment.
- `ExeQue\Monocle\Contracts\Lenses\Resettable` - `reset()` clears what the hook keeps between operations (such as the operation it started) when a capture ends. Keep anything you hold per request here, so a long-running worker (Octane, a queue worker) starts every capture clean.

## An example

A controller that runs webonyx/graphql-php itself fires no events Monocle can see. Fire two of your own around the execution - they cost nothing where Monocle is not installed - and listen to them in a hook.

The events:

```php
namespace App\GraphQL\Events;

use GraphQL\Executor\ExecutionResult;
use GraphQL\Server\OperationParams;

final readonly class OperationStarting
{
    public function __construct(public OperationParams $params)
    {
    }
}

final readonly class OperationExecuted
{
    public function __construct(public ExecutionResult $result)
    {
    }
}
```

Fired from the controller:

```php
event(new OperationStarting($params));

$result = GraphQL::executeQuery($schema, $params->query, null, $context, $params->variables, $params->operation);

event(new OperationExecuted($result));
```

The hook:

```php
namespace App\Monocle;

use App\GraphQL\Events\OperationExecuted;
use App\GraphQL\Events\OperationStarting;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLHook;
use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLLens;

final class StandardServerHook implements GraphQLHook, RequiresPackages, Resettable
{
    /** @var array<string, mixed>|null The operation started and not yet finished. */
    private ?array $started = null;

    public function __construct(
        private readonly Eyepiece $eyepiece,
    ) {
    }

    public static function packages(): array
    {
        return ['webonyx/graphql-php'];
    }

    public function hook(GraphQLLens $lens): void
    {
        // Eyepiece::listen() keeps a failure in the hook from reaching your application.
        $this->eyepiece->listen(OperationStarting::class, function (OperationStarting $event) use ($lens): void {
            $this->started = $lens->start('standard', null, $event->params->query, $event->params->operation, $event->params->variables);
        });

        $this->eyepiece->listen(OperationExecuted::class, function (OperationExecuted $event) use ($lens): void {
            if ($this->started !== null) {
                $lens->finish($this->started, $event->result->errors);
            }

            $this->started = null;
        });
    }

    public function reset(): void
    {
        $this->started = null;
    }
}
```

Listen with `$this->eyepiece->listen()` rather than `Event::listen()`: an exception in your hook is then logged and shown as a warning on the capture instead of breaking the request, and the time it takes counts as Monocle's own. For hooks into something other than Laravel's events (a callback a package calls, a decorator), wrap the closure with `$this->eyepiece->wrap()` for the same reasons. See [Writing a lens](../writing-a-lens.md#recording).

## Registering it

Add the hook to the lens's `hooks` option in `config/monocle.php`. The list replaces the default, so keep the built-in hooks you want:

```php
use App\Monocle\StandardServerHook;
use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLLens;
use ExeQue\Monocle\Lenses\Packages\GraphQL\Lighthouse\LighthouseHook;
use ExeQue\Monocle\Lenses\Packages\GraphQL\Rebing\RebingHook;

'lenses' => [
    GraphQLLens::class => [
        'hooks' => [
            // nuwave/lighthouse
            LighthouseHook::class,
            // rebing/graphql-laravel
            RebingHook::class,
            // our own endpoint
            StandardServerHook::class,
        ],
    ],
],
```

A class in the list that does not implement `GraphQLHook` stops the application from booting with an `InvalidGraphQLHookException` naming it.

The Request tab gets its operation cards from `GraphQLCapture`, registered for the route names `graphql` and `graphql.*`. When your endpoint has another route name, add it under the request lens's `capture.routes`; see [Request](request.md#response-captures).

## Testing it

Enable Monocle and the hook before the application boots, then start a capture, run an operation and finalize the capture. In a package tested with Orchestra Testbench:

```php
use App\Monocle\StandardServerHook;
use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLLens;

protected function defineEnvironment($app): void
{
    $app['config']->set('monocle.enabled', true);
    $app['config']->set('monocle.throw_failures', true);
    $app['config']->set('monocle.lenses.'.GraphQLLens::class, ['hooks' => [StandardServerHook::class]]);
}
```

```php
use App\GraphQL\Events\OperationExecuted;
use App\GraphQL\Events\OperationStarting;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Server\OperationParams;

app(Eyepiece::class)->start(CaptureType::Request);

event(new OperationStarting(OperationParams::create(['query' => 'query Me { me { id } }'])));
event(new OperationExecuted(new ExecutionResult(['me' => ['id' => 1]])));

$row = app(Eyepiece::class)->finalize()->rows('graphql')[0];

expect($row)->toMatchArray(['server' => 'standard', 'type' => 'query', 'operation' => 'Me', 'status' => 'ok']);
```

In an application, set `MONOCLE_ENABLED` and `MONOCLE_THROW_FAILURES` in `phpunit.xml` instead and send a real request to your endpoint; see [Writing a lens](../writing-a-lens.md#testing).

## Limits

- A hook records whole operations. How long single fields took is not recorded; the queries and calls the resolvers made sit under the operation's span on the Timeline.
- The Fields table shows only what the hook passes as `fields` in `$extra`. The built-in hooks find resolvers from the server's own schema; a hook of your own has to do the same, or leave the table out.
- Hooks are booted once, with the lens. A hook cannot be added or removed while the application runs.
