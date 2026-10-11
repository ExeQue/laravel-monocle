<!-- Part of the Monocle documentation - see README.md -->

# AI

Part of [what gets recorded](../lenses.md).

![The AI tab](../images/56-lens-ai-light.webp#gh-light-mode-only)
![The AI tab](../images/56-lens-ai-dark.webp#gh-dark-mode-only)

Section `ai`: calls to `laravel/ai` agents - agent, provider, model, prompt, response, tool calls, tokens, duration and status. The timeline shows each agent with its steps and tool calls; an agent called from inside a tool is shown under that tool. Section `ai_tools`, on the same tab: one row per tool call - tool, agent, status, arguments, result or exception, and how long it took. An agent offered as a tool (a sub-agent) is marked as one, names that agent, and gets its own row in `ai` linked to the agent that called it. The overview adds the time spent in tools and the number of tool calls.

Error: an agent or tool call that failed.

Needs `laravel/ai`.

Option: `max_text` (no limit) - shorten long prompts and responses.
