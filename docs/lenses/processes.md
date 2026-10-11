<!-- Part of the Monocle documentation - see README.md -->

# Processes

Part of [what gets recorded](../lenses.md).

![The Processes tab](../images/44-lens-processes-light.webp#gh-light-mode-only)
![The Processes tab](../images/44-lens-processes-dark.webp#gh-dark-mode-only)

Section `processes`: external commands started with Laravel's `Process` facade - `run()`, `start()`, pools and pipes - with the command line, working directory, exit code, whether it succeeded or timed out, duration and the size of its output and error output. Each process is a `process` span on the timeline. A process started in the background (`start()` or a pool) runs from the moment it started until you waited for it; one nobody waited for ends with the capture and has no exit code. `Process::fake()` keeps working, and faked processes are recorded too.

Processes started without the facade - Symfony's `Process` used directly, `exec()`, `shell_exec()`, `proc_open()` and so on - are not recorded.

Error: a process that exited with a non-zero code, timed out or could not be started.

Warning: a process slower than `slow_ms`.

Options:

- `slow_ms` (default 1000) - a process taking at least this long gives a warning.
