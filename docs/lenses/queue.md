<!-- Part of the Monocle documentation - see README.md -->

# Queue

Part of [what gets recorded](../lenses.md).

![The Jobs tab](../images/42-lens-queue-light.webp#gh-light-mode-only)
![The Jobs tab](../images/42-lens-queue-dark.webp#gh-dark-mode-only)

Section `jobs`: jobs dispatched during the capture - class, connection, queue, delay, job id, batch, chain length and whether it ran inline. For a queued job, `run` links to the job's own capture once a worker has run it (*pending* until then). The summary counts the jobs dispatched and those that ran inline.

On the job's own capture, section `job` holds its class, connection, queue, attempt and maximum tries, batch, remaining chain, payload size and, when it failed, the exception.

Every queued job is recorded as its own capture when a worker runs it, linked to the request, command or job that dispatched it. Use `monocle-trace` to follow the chain. Jobs that run immediately (the `sync` connection or `dispatchSync()`) appear in the timeline of the capture that ran them.

Error: a job that failed, on the job's own capture.

![A request and the jobs it set off](../images/20-trace-light.webp#gh-light-mode-only)
![A request and the jobs it set off](../images/20-trace-dark.webp#gh-dark-mode-only)
