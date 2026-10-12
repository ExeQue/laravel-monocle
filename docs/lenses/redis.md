<!-- Part of the Monocle documentation - see README.md -->

# Redis

The Redis lens records every command sent through Laravel's Redis connections while a request, job or command is captured. This includes the commands the cache and queue drivers send when they use Redis, as well as locks, rate limiters and your own `Redis::` calls.

## Rows

Section `redis`, one row per command, in the order they ran.

| Field | Meaning | Unit | Missing when |
| --- | --- | --- | --- |
| `command` | The command, as it was called, such as `get`, `setex` or `eval`. | - | never |
| `parameters` | The arguments sent with the command, with credentials and secret values masked (see [Masking](../configuration.md#masking) below). | - | empty list when there were none |
| `connection` | Name of the Redis connection, as in `config/database.php`. | - | never |
| `duration` | How long the command took. | ms | never |
| `time` | When the command started, from the start of the capture. | ms | never |
| `origin` | The line in your code that led to the command. | - | `null` when [`origin`](../configuration.md#origin) is turned off |

## Totals, warnings and errors

Only given when at least one command ran.

| Total | Label | Meaning |
| --- | --- | --- |
| `redis_ms` | spent | Sum of all command durations, in ms. |
| `redis_commands` | commands | Number of commands. |
| `redis_by_command` | - | Number of commands per command name, in upper case, most frequent first. |

This lens reports no warnings and no errors. Slow cache calls on a Redis store are warned about by the [Cache lens](cache.md).

## In the dashboard

The **Redis** tab starts with the totals and lists the commands in order with their parameters, connection, duration and the line of your code behind them.

Group the rows with the chips at the top:

- **in order** - every command as it ran.
- **by command** - one group per command name.

Each group shows its count and its total, average and longest time, and folds out to its commands. A row folds out to every field in full.

## For agents

See how many commands a request sent, how long they took in all, and the first 50 of them, with `monocle-show`:

```json
{"id": "latest", "sections": ["summary", "redis"], "limit": 50}
```

Find the slowest commands with `monocle-rows`:

```json
{
  "section": "redis",
  "sort": "duration", "dir": "desc",
  "fields": ["command", "parameters", "duration", "origin"]
}
```

Find the commands that touched a key with `monocle-rows`:

```json
{"section": "redis", "search": "rate-limit:login"}
```

## Configuration

The lens is listed in the `lenses` block of `config/monocle.php`, keyed by its class. Set it to `true` to run it or `false` to turn it off. See [Lenses](../configuration.md#lenses).

The lens has no options.

Other settings that affect this lens:

- [`enabled`](../configuration.md#enabled) - when Monocle is off, nothing is recorded.
- [`except`](../configuration.md#except) - requests and commands listed there are not recorded.
- [`origin`](../configuration.md#origin) - whether each command gets the line of your code that led to it.
- [`dashboard.tabs`](../dashboard.md#configuration) - where the Redis tab goes among the tabs; it is listed as `RedisLens::class`.
- [Masking](../configuration.md#masking) - every argument of `AUTH` and the username and password after `AUTH` in `HELLO` are masked. Values written by `SET`, `SETNX`, `SETEX`, `PSETEX`, `GETSET`, `APPEND`, `MSET`, `MSETNX`, `HSET`, `HSETNX` and `HMSET` are masked when their key (or hash field) matches `mask.inputs`. Other parameters, such as values read back or written by other commands, are recorded as they are. Entries keyed by `RedisLens::class` apply to this lens only.
- Laravel's own `config/database.php` (the `redis` block) defines the connections whose names appear in `connection`.

```php
use ExeQue\Monocle\Lenses\RedisLens;

'lenses' => [
    RedisLens::class => true,
],
```

## Requirements and limits

The application needs a working Redis setup in Laravel: the `phpredis` extension or the `predis/predis` package, and a connection in `config/database.php`. Without Redis the lens records nothing. The lens turns on Laravel's Redis command events by itself.

- Only commands sent through Laravel's Redis connections are recorded. Commands sent on the raw client are not.
- Commands queued inside a `pipeline()` or `transaction()` callback are not listed one by one.
- Commands that failed are not recorded.
