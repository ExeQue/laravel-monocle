<!-- Part of the Monocle documentation - see README.md -->

# HTTP client

![The HTTP tab](../images/41-lens-http-client-light.webp#gh-light-mode-only)
![The HTTP tab](../images/41-lens-http-client-dark.webp#gh-dark-mode-only)

The HTTP client lens records every outgoing request made with Laravel's `Http` client, in requests, jobs and commands alike. Each call becomes a row in the `http` section when its response arrives or its connection fails.

## Rows

Every row in the `http` section has these fields:

| Field | Meaning | Unit | Missing when |
|---|---|---|---|
| `method` | HTTP method, such as `GET` or `POST` | | never |
| `url` | Full URL, with query values whose names match `mask.inputs` masked | | never |
| `host` | Host part of the URL | | never |
| `endpoint` | Method and URL without the query, with numeric ids, UUIDs and long hex ids in the path replaced by `{id}`, such as `GET https://api.example.com/orders/{id}` | | never |
| `request_headers` | Headers sent, masked by `mask.headers` | | never |
| `request_bytes` | Size of the request body | bytes | never (0 without a body) |
| `request_payload` | A JSON or form body as fields, masked like request input | | `null` when the body is empty or not a list of fields |
| `request_body` | The raw request body, with JSON and form bodies masked | | `null` unless the `bodies` option is on |
| `status` | Response status code | | `null` when no response arrived |
| `response_headers` | Headers received, masked by `mask.headers` | | empty when no response arrived |
| `response_bytes` | Size of the response body | bytes | `null` when no response arrived |
| `response_capture` | The response as read by the capture for its content type: `capture` (the class), `cards` (masked like input) and `raw` (the body, with JSON and form bodies masked) | | `null` when no capture matches the content type, or no response arrived |
| `response_body` | The raw response body, with JSON and form bodies masked | | `null` unless the `bodies` option is on and no capture handled the response |
| `duration` | How long the call took | ms | `null` only when a failed connection cannot be matched to its start |
| `timing` | Where the time went: `dns`, `connect`, `tls`, `wait` (time to first byte), `download` and `redirect` in ms, `remote` (address and port connected to), `protocol` (such as `HTTP/2`) and `redirects` (how many were followed) | ms | `null` for faked calls (`Http::fake()`) and failed connections; a phase that was never reached, such as `tls` on plain HTTP, is left out |
| `failed` | `true` when the status is 400 or above, or no response arrived | | never |
| `exception` | For a failed connection: `class`, `message`, `file` (file:line) and `previous` (the exceptions it wraps) | | `null` when a response arrived |
| `pool` | A number shared by the requests sent together by one `Http::pool()` or `Http::batch()`, counting from 1 in each capture | | `null` for a request sent on its own |
| `time` | When the call started, counted from the start of the capture | ms | never |
| `origin` | The file and line of your code that made the call, with a short call chain | | `null` when [`origin.enabled`](../configuration.md#origin) is off |

## Totals, warnings and errors

The summary gets these totals when at least one call was made:

| Total | Meaning |
|---|---|
| `http_ms` | Time spent in all calls together, in ms |
| `http_by_host` | Number of calls per host |
| `http_calls` | Number of calls |
| `http_failed` | Number of calls where `failed` is `true` |

Errors: every failed call (status 400 or above, or no response at all) is an error. The summary lists the first three, such as `HTTP call failed: GET https://api.example.com/orders (503)`. For a call without a response, the exception message (or `no response`) takes the place of the status. `http_failed` holds the full count.

This lens reports no warnings, and it has no threshold for slow calls.

## In the dashboard

The **HTTP** tab lists the calls with method, status, URL, request size, response size, pool and duration, plus the line of code that made each call. Statuses are coloured; a call without a response shows `failed`. Group the rows **by endpoint**, **by host**, **by pool** or **by status**. Each group shows its count, how many failed, and the total, average and maximum time.

A row folds out to:

- the method and full URL, with a copy button
- status, duration, start time, bytes sent and received, and the pool
- a timing bar split into DNS, connect, TLS, waiting, download and redirects, with the remote address, protocol and redirects followed (real calls only)
- the exception and the exceptions it wraps, for a failed connection
- request headers and response headers
- the payload as fields (or the raw request body with `bodies` on)
- the response as its capture shows it, with the raw body under **Raw body**, or the raw body alone; JSON is pretty-printed
- the line of code that made the call

![A call folded out, with the timing of its connection](../images/17-http-details-light.webp#gh-light-mode-only)
![A call folded out, with the timing of its connection](../images/17-http-details-dark.webp#gh-dark-mode-only)

![A call that failed on an expired certificate](../images/17-http-tls-details-light.webp#gh-light-mode-only)
![A call that failed on an expired certificate](../images/17-http-tls-details-dark.webp#gh-dark-mode-only)

On the timeline, each call is a line inside the span that made it, and the requests of one `Http::pool()` or `Http::batch()` hang under one pool row. Time spent in calls shows as HTTP in the overview's time breakdown.

## For agents

`monocle-rows`: which calls in the latest capture failed, and why.

```json
{
  "section": "http",
  "where": [{"field": "failed", "op": "=", "value": "true"}],
  "fields": ["method", "url", "status", "exception.message", "duration", "origin"]
}
```

`monocle-rows`: which calls were slowest, and where their time went (DNS, TLS, waiting on the server).

```json
{
  "section": "http",
  "sort": "duration", "dir": "desc", "limit": 5,
  "fields": ["endpoint", "status", "duration", "timing", "pool"]
}
```

`monocle-list`: which recent captures made outgoing calls and have errors.

```json
{"has": ["http"], "has_errors": true, "since": "15m"}
```

`monocle-show` with `"sections": ["http"]` returns the first rows next to the summary, which carries the totals and the `HTTP call failed` errors.

## Configuration

The lens is on by default. In the `lenses` block of `config/monocle.php`, key it by its class: `true` runs it with the defaults, `false` turns it off, and an array passes options (see [Lenses](../configuration.md#lenses)).

| Option | Default | Effect |
|---|---|---|
| `bodies` | `false` | Also keep the raw request body (`request_body`), and the raw response body (`response_body`) of responses no capture handles. JSON and form bodies are masked; other bodies are kept as they are. |
| `capture.response_types` | `application/json` and `application/*+json` with `JsonCapture` | Content type (wildcards allowed) => the class that reads the response into `response_capture`. The first matching pattern wins. Setting it replaces the default, so list the defaults you want to keep. Responses of other types keep only their size. |

The response captures are the same ones the request lens uses; see [Response captures](request.md#response-captures).

Other settings that affect this lens:

- [`mask.headers`](../configuration.md#masking) masks request and response headers. Entries keyed by `HttpClientLens::class` apply to this lens only.
- [`mask.inputs`](../configuration.md#masking) masks query values in the URL, payload fields, capture cards and JSON or form bodies. An expression keyed by `HttpClientLens::class` applies to this lens only.
- [`origin`](../configuration.md#origin) decides whether rows carry the line of code that made the call.
- [`except`](../configuration.md#except) keeps whole requests and commands out of recording, including the calls they make.

```php
// config/monocle.php
use ExeQue\Monocle\Lenses\HttpClientLens;
use ExeQue\Monocle\Lenses\Request\JsonCapture;
use ExeQue\Monocle\Support\Masks\FullMask;

'lenses' => [
    // ...
    HttpClientLens::class => [
        'bodies' => true,
        'capture' => [
            'response_types' => [
                'application/json' => JsonCapture::class,
                'application/*+json' => JsonCapture::class,
            ],
        ],
    ],
],

'mask' => [
    'headers' => [
        // ...the shared headers...
        HttpClientLens::class => ['x-api-key' => FullMask::class],
    ],
    'inputs' => ['/pass|token|secret|authorization/i', HttpClientLens::class => '/client_secret/i'],
    // ...
],
```

## Requirements and limits

- Nothing to install: it uses Laravel's HTTP client.
- Only calls made through the `Http` facade (or a `PendingRequest`) are recorded. Requests made with Guzzle directly, `file_get_contents()`, raw cURL or an SDK with its own client are not.
- Faked calls (`Http::fake()`) are recorded, but without `timing`.
- A failed connection has no response, so no status, headers, response size or timing; its duration is measured by the wall clock.
- Bodies that are neither JSON nor form data are kept unmasked when `bodies` is on.
