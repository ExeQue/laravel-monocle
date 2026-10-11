<!-- Part of the Monocle documentation - see README.md -->

# HTTP client

Part of [what gets recorded](../lenses.md).

![The HTTP tab](../images/41-lens-http-client-light.webp#gh-light-mode-only)
![The HTTP tab](../images/41-lens-http-client-dark.webp#gh-dark-mode-only)

Section `http`: outgoing requests made with Laravel's `Http` client - method, URL, status, sizes, duration and failures. Requests sent together with `Http::pool()` or `Http::batch()` share a `pool` number, and the timeline shows them under one pool row. Real calls also record where the time went: DNS lookup, connecting, the TLS handshake, waiting for the first byte, downloading and redirects, with the address connected to and the protocol. A JSON or form body sent is kept as fields, masked like request input. Responses are read with the same captures the request lens uses, chosen by content type, and kept with their raw body. Secrets in headers and query strings are masked.

Error: a call that got a 4xx or 5xx response or no response at all.

Options:

- `capture.response_types` - content type (wildcards allowed) => capture. By default `application/json` and `application/*+json` with `JsonCapture`. Setting it replaces the default.
- `bodies` (default false) - also keep the raw request body, and the raw response body of responses no capture handles.

![A call folded out, with the timing of its connection](../images/17-http-details-light.webp#gh-light-mode-only)
![A call folded out, with the timing of its connection](../images/17-http-details-dark.webp#gh-dark-mode-only)

![A call that failed on an expired certificate](../images/17-http-tls-details-light.webp#gh-light-mode-only)
![A call that failed on an expired certificate](../images/17-http-tls-details-dark.webp#gh-dark-mode-only)
