<!-- Part of the Monocle documentation - see README.md -->

# MCP servers

The MCP servers lens records every call to the MCP servers your application provides with `laravel/mcp`: which method was called, which tool, resource or prompt, the arguments, how long it took and whether it failed. Over HTTP a call is part of the request capture; over stdio each call becomes a capture of its own, of type `mcp`.

## Rows

### Section `mcp`

Over HTTP, `mcp` has one row per call on the request capture. Over stdio, the capture is the call, and `mcp` holds the same fields once, as facts rather than rows. The capture is named `{server}: {method} {primitive}`, such as `weather: tools/call forecast`, and its status is `ok` or `error`.

Recorded methods are `tools/call`, `resources/read`, `prompts/get`, `tools/list`, `resources/list`, `resources/templates/list`, `prompts/list`, `completion/complete`, `initialize`, `ping` and the server's discovery method.

| Field | Meaning | Unit | Missing when |
| --- | --- | --- | --- |
| `server` | The server's name. | - | never |
| `method` | The JSON-RPC method, such as `tools/call`. | - | never |
| `primitive` | The tool or prompt name, or the resource URI. | - | the method is not about one tool, prompt or resource, such as `tools/list` |
| `arguments` | The arguments sent to a tool or prompt. | - | the call had none |
| `protocol_version` | The protocol version the client asked for. | - | only set on `initialize` |
| `duration` | How long the server took to answer. For a streamed answer, until the last chunk was sent. | ms | never |
| `is_error` | `true` when the answer was a JSON-RPC error, a tool result marked as an error, a streamed answer with any error chunk, or the handler threw. | - | never (`false`) |
| `streamed_chunks` | How many chunks a streamed answer sent. | count | never (`0` for an answer that was not streamed) |
| `exception` | The exception the handler threw. | - | the handler did not throw |
| `time` | When the call started, counted from the start of the capture. | ms | stdio captures |

Rows in `mcp` have no `origin`.

### In the timeline

Each call is an `mcp` span named after the method and primitive, such as `tools/call forecast`, with its server, method, primitive, arguments, protocol version, `is_error` and `chunks`. Spans started while the call ran sit under it.

## Totals, warnings and errors

The summary gets these totals when the capture has at least one call:

| Total | Label | Value |
| --- | --- | --- |
| `mcp_ms` | spent | The `duration` of every call added up, in ms. |
| `mcp_calls` | calls | The number of calls. |
| `mcp_errors` | errors | The number of calls with `is_error` set. |

This lens reports no warnings. It reports one error per call with `is_error` set, for the first three such calls:

- `MCP {method} {primitive} returned an error.`

A stdio call that ended in an error also gives its capture the status `error`. See [Warnings and errors](../dashboard.md#warnings-and-errors) for how errors are shown.

## In the dashboard

On a request capture, the **MCP** tab lists the calls in the order they happened. The columns are the fields that have a value in some row, up to eight, with `duration` last. There are no grouping chips. Click a row to fold it out to every field, including the arguments and the exception.

On a capture of type `mcp`, the MCP tab shows the call's fields as a list. In the capture list, these captures have the type `mcp`.

## For agents

List the stdio calls to your servers from the last 15 minutes:

```json
{
  "tool": "monocle-list",
  "arguments": {"type": "mcp", "since": "15m"}
}
```

Show one stdio call with what it did, using an id from the list:

```json
{
  "tool": "monocle-show",
  "arguments": {"id": "<capture id>", "sections": ["summary", "mcp", "timeline"]}
}
```

On a request capture made over HTTP, list the calls that failed:

```json
{
  "tool": "monocle-rows",
  "arguments": {
    "section": "mcp",
    "where": [{"field": "is_error", "op": "=", "value": "true"}],
    "fields": ["server", "method", "primitive", "arguments", "exception.message", "duration"]
  }
}
```

See [For AI agents](../agents.md#monocle-list) for every argument.

## Configuration

Turn the lens on or off under `lenses` in `config/monocle.php`, keyed by `McpLens::class`: `true` runs it, `false` turns it off. See [Lenses](../configuration.md#lenses). The lens has no options of its own.

Other settings that affect this lens:

- [`mcp.except_servers`](../configuration.md#mcp) - servers whose calls are never recorded, matched by name in any letter case. Default `['monocle', 'laravel-boost']`, so calls to Monocle's own server and to Laravel Boost are left out. Setting it replaces the default.
- [`except`](../configuration.md#except) - calls over HTTP to a path in `except.paths` are not recorded, since the request is not.
- [`dashboard.tabs`](../dashboard.md#configuration) - where the MCP tab sits among the tabs.
- [Masking](../configuration.md#masking) - argument keys matching `mask.inputs` are masked, also inside nested arguments. Entries keyed by `McpLens::class` apply to this lens only.

```php
use ExeQue\Monocle\Lenses\Packages\Laravel\McpLens;

'lenses' => [
    // ...
    McpLens::class => true,
],

'mcp' => [
    'enabled' => true,
    'server' => 'monocle',
    'except_servers' => ['monocle', 'laravel-boost', 'internal-tools'],
],
```

## Requirements and limits

- Needs `laravel/mcp`. Without it the lens does nothing.
- Records the servers your application provides, not MCP servers your application calls as a client.
- Arguments are masked by key only. A secret under a key that does not match `mask.inputs` is stored as it is.
- The answer a call returned is not stored; only whether it was an error and how many chunks it streamed.
