<!-- Part of the Monocle documentation - see README.md -->

# Prism

Part of [what gets recorded](../lenses.md).

![The Prism tab](../images/57-lens-prism-light.webp#gh-light-mode-only)
![The Prism tab](../images/57-lens-prism-dark.webp#gh-dark-mode-only)

Section `prism`: calls made with `prism-php/prism` - operation (text, structured, stream, embeddings, images, moderation, text to speech, speech to text), provider, model, prompt, response, structured output, finish reason, steps, tool calls, the tools offered, tokens, duration and status. Each call is shown in the timeline; a stream shows its steps. A stream that is not read to the end has the status `abandoned`. Section `prism_tools`, on the same tab: one row per tool Prism ran - tool, status, arguments, result or exception, and how long it took; each is also shown in the timeline under its call. The overview adds the time spent in tools and the number of tool calls. The HTTP requests Prism sends to the provider are in the `http` section too.

Needs `prism-php/prism`. Calls made with `Prism::fake()` are recorded as well.

Error: a call that failed, or a tool that failed during a stream.

Option: `max_text` (no limit) - shorten long prompts and responses.
