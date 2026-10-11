<!-- Part of the Monocle documentation - see README.md -->

# Prism

Part of [what gets recorded](../lenses.md).

![The Prism tab](../images/57-lens-prism-light.webp#gh-light-mode-only)
![The Prism tab](../images/57-lens-prism-dark.webp#gh-dark-mode-only)

The Prism lens records every call made with `prism-php/prism` while a request, job, command or MCP call is recorded: text, structured, stream, embeddings, images, moderation, text to speech and speech to text. Each call gets its prompt, response, tokens and time, and every tool Prism runs during a call is timed on its own.

## Rows

### Section `prism`: one row per call

A row is written when the call ends. For a stream, that is when it has been read to the end, when it throws, or when your code stops reading it.

| Field | Meaning | Unit | Missing when |
| --- | --- | --- | --- |
| `operation` | `text`, `structured`, `stream`, `embeddings`, `images`, `moderation`, `text_to_speech` or `speech_to_text`. | - | never |
| `nested` | `true` when the call was made while another Prism call ran, such as from one of its tools. | - | never (`false`) |
| `provider` | The provider the call used. | - | the request does not name one |
| `model` | The model, taken from the response when it reports one. | - | the request and response do not name one |
| `status` | `ok`, `failed`, or `abandoned` for a stream that was not read to the end. | - | never |
| `prompt` | The prompt. For embeddings, the inputs one per line; for text to speech, the text to speak. Shortened to `max_text` characters when set. | - | the operation has no prompt |
| `response` | The response text. For a stream, the text read so far. Shortened to `max_text` characters when set. | - | the operation returns no text, the call failed, or a stream produced no text |
| `structured` | The structured output. | - | not a `structured` call, or it failed |
| `finish_reason` | Why the model stopped. | - | the response has none, the call failed, or a stream was not read to the end |
| `steps` | How many steps the call took. | count | never (`0`) |
| `tool_calls` | How many tool calls the response reports. | count | never (`0`) |
| `tools` | The names of the tools offered with the request. | - | empty when no tools were offered |
| `failed_tools` | For a stream, the names of tools the stream reported as failed. | - | empty for other operations, or when none failed |
| `tokens` | `input`, `output`, `cache_read`, `cache_write` and `reasoning` tokens. Embeddings only fill `input`. | tokens | never (zeros) |
| `streamed` | `true` for a stream. | - | never (`false`) |
| `error` | An error message a stream reported while it kept going. | - | no error was reported, or not a stream |
| `exception` | The exception the call threw. | - | the call did not throw |
| `duration` | How long the call took. For a stream, the time from the first read to the last. | ms | never |
| `time` | When the call started, counted from the start of the capture. | ms | never |
| `origin` | The line in your code that made the call. | - | no application code was on the way |

### Section `prism_tools`: one row per tool call

| Field | Meaning | Unit | Missing when |
| --- | --- | --- | --- |
| `tool` | The tool's name. | - | never |
| `nested` | `true` when the call that ran the tool is itself `nested`. | - | never (`false`) |
| `status` | `ok` or `failed`. | - | never |
| `arguments` | The arguments the model passed to the tool, [masked](../configuration.md#masking) like request input. | - | never |
| `result` | The text the tool returned. JSON text with keys is masked like request input. Shortened to `max_text` characters when set. | - | the tool failed, or returned something other than text |
| `exception` | The exception the tool threw. | - | the tool succeeded |
| `duration` | How long the tool took, including any Prism calls it made itself. | ms | never |
| `time` | When the tool started, counted from the start of the capture. | ms | never |
| `origin` | The line in your code that led to the tool call. | - | no application code was on the way |

Tool rows do not say which call they belong to; the timeline shows that.

### In the timeline

Each call is a `prism.call` span named after the operation, provider and model, such as `text openai/gpt-4o`. A stream has a `prism.step` span per step (`step 1`, `step 2`, ...). Each tool call is a `prism.tool` span under the call or step that was running, with its status and time (`tool_ms`).

A tool that makes a Prism call of its own, for example a tool that hands part of the work to another model, works as a sub-agent: that call appears as a `prism.call` span under the tool's `prism.tool` span, and gets its own row in `prism`. The tool's `duration` includes it.

## Totals, warnings and errors

The summary gets these totals when the capture has at least one `prism` row:

| Total | Label | Value |
| --- | --- | --- |
| `prism_ms` | spent | The `duration` of the `prism` rows that are not `nested` added up, in ms. A call made inside a tool runs in the time of the call that ran the tool, so it is not added again. |
| `prism_tokens` | - | `input`, `output`, `cache_read`, `cache_write` and `reasoning` tokens over all rows. |
| `prism_by_model` | - | The number of calls per `provider/model`. |
| `prism_calls` | calls | The number of `prism` rows, nested calls included. |
| `prism_tool_ms` | in tools | The `duration` of the `prism_tools` rows that are not `nested` added up, in ms. Only when tools ran. |
| `prism_tool_calls` | tool calls | The number of `prism_tools` rows, nested ones included. Only when tools ran. |

This lens reports no warnings. It reports these errors, at most five per capture:

- `Prism {operation} on {provider}/{model} failed: {message}` - a call ended with status `failed`.
- `Prism tool {name} failed: {message}` - a tool threw.
- `Tool {name} failed during Prism {operation} on {provider}/{model}.` - a stream reported a failed tool, and the capture has no `prism_tools` rows (for instance a faked stream, where no tool actually ran). When tool rows exist, the error above is used instead.

A stream with status `abandoned` and a stream `error` are not reported as errors.

See [Warnings and errors](../dashboard.md#warnings-and-errors) for how they are shown.

## In the dashboard

The **Prism** tab has two tables, both in the order things happened.

- The calls (`prism`). The columns are the fields that have a value in some row, up to eight, with `duration` last. `status` is coloured.
- **Tool calls** (`prism_tools`), with the columns tool, status and duration, plus where in your code it came from. Its chips group the tool calls by tool, each group with its count, failures and timing.

Click a row to fold it out. A call shows its kind (text, structured, stream, embeddings, images, moderation, text to speech or speech to text), provider and model, then its status, finish reason, steps, tool calls, whether it was streamed or nested, its duration and when it started. Below that come the tokens (input, output, and cache read, cache write and reasoning when there are any), the exception or stream error when it failed, the prompt and the response (or the structured output, pretty printed) in boxes that scroll and copy, the tools it offered (failed ones in red) and the line in your code that made the call.

![A Prism call folded out](../images/57-lens-prism-details-light.webp#gh-light-mode-only)
![A Prism call folded out](../images/57-lens-prism-details-dark.webp#gh-dark-mode-only)

A tool call folds out to its status, whether a nested call ran it, its duration, the exception when it failed, the arguments and the result (JSON pretty printed), and where in your code it came from.

The **Timeline** tab shows the call, step and tool spans described above.

## For agents

Find the slowest tools in the latest capture:

```json
{
  "tool": "monocle-rows",
  "arguments": {
    "section": "prism_tools",
    "sort": "duration", "dir": "desc",
    "fields": ["tool", "status", "duration", "exception.message"]
  }
}
```

List the calls that did not finish normally, failed or abandoned:

```json
{
  "tool": "monocle-rows",
  "arguments": {
    "section": "prism",
    "where": [{"field": "status", "op": "!=", "value": "ok"}],
    "fields": ["operation", "provider", "model", "status", "error", "exception.message", "duration"]
  }
}
```

See which call each tool belongs to, and calls made from inside tools:

```json
{
  "tool": "monocle-rows",
  "arguments": {
    "section": "timeline",
    "kinds": ["prism.call", "prism.step", "prism.tool"]
  }
}
```

To find captures that made Prism calls at all, use `monocle-list` with `"has": ["prism"]`. See [For AI agents](../agents.md#monocle-rows) for every argument.

## Configuration

Turn the lens on or off, or pass options, under `lenses` in `config/monocle.php`, keyed by `PrismLens::class`: `true` runs it with defaults, `false` turns it off, and an array sets options. See [Lenses](../configuration.md#lenses).

| Option | Default | Effect |
| --- | --- | --- |
| `max_text` | `null` | Shortens prompts, responses and tool results to this many characters, followed by `...`. `null` keeps them whole. Arguments and structured output are never shortened. |

Other settings that affect this lens:

- [`origin`](../configuration.md#origin) - whether rows get an `origin`, and how many lines of the call chain it has.
- [`except`](../configuration.md#except) - calls made in a request or command that is not recorded are not recorded either.
- [`dashboard.tabs`](../dashboard.md#configuration) - where the Prism tab sits among the tabs.
- [Masking](../configuration.md#masking) - tool arguments, and JSON tool results with keys, are masked like request input. Use `PrismLens::class` as the key under `mask.inputs` for patterns that only apply here. Prompts and responses are free text and are stored as they are.

```php
use ExeQue\Monocle\Lenses\Packages\Prism\PrismLens;

'lenses' => [
    // ...
    PrismLens::class => [
        'max_text' => 2000,
    ],
],
```

## Requirements and limits

- Needs `prism-php/prism`. Without it the lens does nothing.
- Calls made with `Prism::fake()` are recorded too.
- Only tools given to Prism as `Tool` objects are timed.
- Prompts, responses and plain-text tool results are not masked.
- The HTTP requests Prism sends to the provider are recorded by the [HTTP client](http-client.md) lens, not here.
- `prism_ms` and `prism_tool_ms` count time only at the top: a call made while another call ran, and the tools it ran, are part of that call. Work your code does between two events of a stream it reads is not, so a call made there counts on its own while the stream's `duration` includes it too.
