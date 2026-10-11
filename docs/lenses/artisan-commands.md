<!-- Part of the Monocle documentation - see README.md -->

# Artisan commands

Part of [what gets recorded](../lenses.md).

![A command capture](../images/43-lens-commands-light.webp#gh-light-mode-only)
![A command capture](../images/43-lens-commands-dark.webp#gh-dark-mode-only)

Section `command`: every artisan command is recorded as its own capture, with its arguments, options (secrets masked) and exit code. A command called from somewhere else, like `Artisan::call()` inside a request, job or another command, appears in that capture's timeline and as a row in section `commands`: name, class, arguments, options (masked), exit code, duration and the line that called it. A request or job that called commands gets a **Commands** tab; on a command capture they are listed on its own tab. A non-zero exit code marks the row as failed and is reported as an error. Totals: `commands_ms` (time spent), `commands` and `commands_failed`.
