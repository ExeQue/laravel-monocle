<?php

declare(strict_types=1);

namespace Workbench\App\Demos;

/**
 * Every demo the workbench offers, grouped by lens. The index lists them
 * by lens and by alarm, and a demo's result page finds itself here by
 * its path to say what to look for.
 *
 * A demo: method, path, name, text (one line), tab (the dashboard tab to
 * open), alarms (label and tone: bad for errors, warn for warnings, info
 * for plain markers) and look (tab label and what to look for there).
 *
 * @phpstan-type Demo array{method: string, path: string, name: string, text: string, tab?: string, alarms?: list<array{0: string, 1: string}>, look?: list<array{0: string, 1: string}>}
 * @phpstan-type Lens array{id: string, name: string, category: string, blurb: string, demos: list<Demo>}
 */
final class Catalog
{
    /** The order the categories are shown in. */
    public const array CATEGORIES = ['Data', 'Work', 'People', 'Output', 'Debugging', 'Packages'];

    /**
     * @return Demo
     */
    public static function showcase(): array
    {
        return [
            'method' => 'GET',
            'path' => '/demo/showcase',
            'name' => 'Publish a post: every lens in one request',
            'text' => 'Middleware, cache, validation, a gate, a transaction, model changes, a job, mail, HTTP calls, an agent with tools and a sub-agent, Prism, the filesystem, an Artisan command and custom spans, with warnings and errors to look at.',
            'tab' => 'overview',
            'alarms' => [['HTTP call failed', 'bad'], ['Tool failed', 'bad'], ['Slow query', 'warn'], ['Possible N+1', 'warn']],
            'look' => [
                ['Overview', 'Where the time went, every warning and error, and the totals per lens.'],
                ['Timeline', 'Phases, middleware, the controller and the spans inside it.'],
                ['AI', 'The agent, its tool calls and the sub-agent.'],
            ],
        ];
    }

    /**
     * @return list<Lens>
     */
    public static function lenses(): array
    {
        return [
            self::lens('database', 'Database', 'Data', 'queries and transactions', [
                self::demo('GET', '/demo/database/n-plus-one', 'N+1', 'Loads posts, then each post\'s comments in a loop.', 'queries', [['Possible N+1', 'warn']], [['Queries', 'Group by caller to find the line that loops.']]),
                self::demo('GET', '/demo/database/slow', 'Slow query', 'A recursive query that takes long enough to be flagged as slow.', 'queries', [['Slow query', 'warn']]),
                self::demo('GET', '/demo/database/duplicate', 'Duplicate query', 'Runs the same query three times.', 'queries', [['Duplicate queries', 'warn']]),
                self::demo('GET', '/demo/database/rollback', 'Rolled back transaction', 'Inserts a post inside a transaction that is rolled back.', 'timeline', [['Rolled back', 'info']]),
            ]),
            self::lens('models', 'Eloquent', 'Data', 'model changes', [
                self::demo('GET', '/demo/eloquent', 'Model changes', 'Creates, updates and deletes a post.', 'models'),
            ]),
            self::lens('cache', 'Cache', 'Data', 'hits, misses and writes', [
                self::demo('GET', '/demo/cache', 'Hits, misses and writes', 'A miss, a write, a hit and one key read repeatedly.', 'cache', [['Repeated cache reads', 'warn']]),
                self::demo('GET', '/demo/alarms/slow-cache', 'Slow cache call', 'A remember() on a store that takes 30 ms per call: a slow miss and a slow write.', 'cache', [['Slow cache call', 'warn']]),
            ]),
            self::lens('session', 'Session', 'Data', 'what changed', [
                self::demo('GET', '/demo/session', 'Write and flash', 'Puts, increments and flashes session values.', 'session'),
            ]),
            self::lens('jobs', 'Queue', 'Work', 'jobs and traces', [
                self::demo('GET', '/demo/queue', 'Dispatch a job', 'Runs a job on the sync queue.', 'jobs'),
                self::demo('GET', '/demo/queue/database', 'Queue a job', 'Puts a job on the database queue for a worker to run.', 'jobs', [['Pending', 'info']]),
                self::demo('GET', '/demo/queue/countdown', 'Count down', 'Queues a job that queues itself again three times, for a trace four jobs deep.', 'trace', [['Trace', 'info']], [['Trace', 'Work the queue, then open the trace: four jobs deep.']]),
                self::demo('GET', '/demo/queue/work', 'Work the queue', 'Runs every waiting job in a worker process; each job becomes a capture of its own.', 'jobs'),
                self::demo('GET', '/demo/queue/chain', 'Job dispatching a job', 'A job that dispatches another job.', 'jobs'),
                self::demo('GET', '/demo/queue/failing', 'Failing job', 'A job on the sync queue that throws; the failure is caught and shown.', 'jobs'),
                self::demo('GET', '/demo/alarms/failed-job', 'Failed queued job', 'Queues a job that throws on the database queue; work the queue and open the job capture.', 'jobs', [['Job failed', 'bad']], [['Overview', 'Run /demo/queue/work, then open the BrokenJob capture: the failure is its error.']]),
            ]),
            self::lens('commands', 'Artisan', 'Work', 'commands run from code', [
                self::demo('GET', '/demo/artisan', 'Artisan::call()', 'Calls the demo:greet command from a request.', 'commands'),
                self::demo('GET', '/demo/alarms/failed-command', 'Failed command', 'Calls demo:fail, which exits with code 1.', 'commands', [['Command failed', 'bad']]),
                self::demo('GET', '/demo/alarms/failed-scheduled-task', 'Failed scheduled task', 'Runs schedule:run in a process; the nightly-export task throws.', 'scheduled_tasks', [['Scheduled task failed', 'bad']], [['Overview', 'Open the artisan schedule:run capture: the failed task and its exception.']]),
            ]),
            self::lens('processes', 'Processes', 'Work', 'external processes', [
                self::demo('GET', '/demo/process', 'Run processes', 'Runs php -v and a command that exits with 3.', 'processes', [['Process failed', 'bad']]),
                self::demo('GET', '/demo/alarms/slow-process', 'Slow process', 'A process that takes 1.1 seconds.', 'processes', [['Slow process', 'warn']]),
                self::demo('GET', '/demo/alarms/process-timeout', 'Process timeout', 'A process stopped by its 1 second timeout.', 'processes', [['Process timed out', 'bad'], ['Slow process', 'warn']]),
            ]),
            self::lens('http', 'HTTP client', 'Work', 'outgoing calls', [
                self::demo('GET', '/demo/http', 'Faked remote API', 'Calls a faked API, including a call that fails with a 500.', 'http', [['HTTP call failed', 'bad']]),
                self::demo('GET', '/demo/http/failures', 'Failed connections', 'Expired and self-signed certificates, an unknown host and an address that does not answer. Needs network access.', 'http', [['HTTP call failed', 'bad']]),
                self::demo('GET', '/demo/alarms/http-connection-failed', 'Unknown host', 'A call to a host that does not exist; works offline.', 'http', [['HTTP call failed', 'bad']]),
            ]),
            self::lens('middleware', 'Middleware', 'Work', 'the request pipeline', [
                self::demo('GET', '/demo/middleware', 'Slow middleware', 'A route behind a middleware that sleeps 150 ms.', 'middleware', [['Slow middleware', 'warn']]),
                self::demo('GET', '/demo/alarms/middleware-short-circuit', 'Short-circuited request', 'A middleware that redirects without handing the request on.', 'middleware', [['Middleware short-circuited', 'warn']]),
            ]),
            self::lens('auth', 'Auth', 'People', 'logins and tokens', [
                self::demo('POST', '/demo/auth/login', 'Log in', 'Logs in ada@example.com with Auth::login().', 'auth'),
                self::demo('POST', '/demo/auth/failed', 'Failed attempt', 'Auth::attempt() with a wrong password.', 'auth'),
                self::demo('POST', '/demo/auth/logout', 'Log out', 'Logs the current user out.', 'auth'),
                self::demo('GET', '/demo/auth/token', 'Sanctum token', 'Creates a token for Ada and calls an auth:sanctum route with it.', 'auth'),
                self::demo('GET', '/demo/impersonate/start', 'Start impersonating', 'Ada impersonates another user with lab404/laravel-impersonate.', 'auth', [['Impersonated by', 'warn']]),
                self::demo('GET', '/demo/impersonate/whoami', 'Who am I', 'While impersonating, the auth tab names who is impersonating.', 'auth', [['Impersonated by', 'warn']]),
                self::demo('GET', '/demo/impersonate/stop', 'Stop impersonating', 'Leaves the impersonation and goes back to Ada.', 'auth'),
                self::demo('GET', '/demo/alarms/lockout', 'Login lockout', 'Wrong passwords until the rate limiter locks the login, then a Lockout event.', 'auth', [['Login locked out', 'warn']]),
            ]),
            self::lens('authorization', 'Authorization', 'People', 'gates and policies', [
                self::demo('GET', '/demo/authorization/allowed', 'Allowed gate', 'A gate check that passes.', 'authorization'),
                self::demo('GET', '/demo/authorization/denied', 'Denied gate', 'A gate check that fails with a 403.', 'authorization', [['Authorization denied', 'warn']]),
            ]),
            self::lens('validation', 'Validation', 'People', 'rules and failures', [
                self::demo('GET', '/demo/validation', 'Failing form request', 'A form that posts invalid input to a form request and shows the errors.', 'validation'),
                self::demo('GET', '/demo/validation/inline?name=Ada&email=ada@example.com', 'Passing inline validation', 'Validates query input with $request->validate().', 'validation'),
            ]),
            self::lens('views', 'Views', 'Output', 'templates and components', [
                self::demo('GET', '/demo/views', 'Components and partials', 'Renders a Blade component in a loop and a partial per post.', 'views'),
                self::demo('GET', '/demo/alarms/repeated-view', 'Repeated view', 'Renders a slow partial 12 times, 40 ms in all.', 'views', [['View rendered repeatedly', 'warn']]),
            ]),
            self::lens('mail', 'Mail and notifications', 'Output', 'what was sent', [
                self::demo('GET', '/demo/mail', 'Mailable and notification', 'Sends a mailable and a mail notification to the log mailer.', 'mail'),
            ]),
            self::lens('filesystem', 'Filesystem', 'Output', 'disk operations', [
                self::demo('GET', '/demo/filesystem', 'Put, get and delete', 'Writes, reads and deletes files on the demo disk.', 'filesystem'),
                self::demo('GET', '/demo/alarms/filesystem-failed', 'Failed read', 'Reads a file that does not exist on the demo disk.', 'filesystem', [['Filesystem operation failed', 'bad']]),
            ]),
            self::lens('events', 'Events', 'Output', 'dispatched events', [
                self::demo('GET', '/demo/events', 'Custom event', 'Dispatches PostPublished, handled by a listener.', 'events'),
            ]),
            self::lens('exceptions', 'Exceptions', 'Debugging', 'what went wrong', [
                self::demo('GET', '/demo/exceptions/reported', 'Reported exception', 'Reports an exception with report() and carries on.', 'exceptions', [['Exception', 'bad']]),
                self::demo('GET', '/demo/exceptions/thrown', 'Thrown exception', 'Throws an exception, resulting in a 500.', 'exceptions', [['Response status 500', 'bad'], ['Exception', 'bad']]),
                self::demo('GET', '/demo/alarms/server-error', 'Server error response', 'Returns a 503 without throwing anything.', 'request', [['Response status 503', 'bad']]),
            ]),
            self::lens('log', 'Log', 'Debugging', 'log entries', [
                self::demo('GET', '/demo/log', 'Log entries', 'Writes info, warning and error entries.', 'log', [['Error logged', 'bad']]),
            ]),
            self::lens('dumps', 'Dumps', 'Debugging', 'dump() and dd()', [
                self::demo('GET', '/demo/dump', 'dump()', 'Dumps a value.', 'dumps'),
                self::demo('GET', '/demo/alarms/dd', 'dd()', 'Calls dd(), which ends the process before the response is sent.', 'dumps', [['dd() ended the process', 'warn'], ['Spans never stopped', 'warn']]),
            ]),
            self::lens('timeline', 'Custom spans', 'Debugging', 'your own timings', [
                self::demo('GET', '/demo/spans', 'Monocle::span() and measure()', 'Times work with custom spans.', 'timeline'),
                self::demo('GET', '/demo/alarms/open-span', 'Span never stopped', 'Starts a span and never stops it.', 'timeline', [['Spans never stopped', 'warn']]),
                self::demo('GET', '/demo/alarms/monocle-failure', 'Monocle failed to record', 'Dispatches an event whose payload throws when the events lens reads it.', 'overview', [['Monocle failed to record', 'warn']]),
            ]),
            self::lens('livewire', 'Livewire', 'Packages', 'components and updates', [
                self::demo('GET', '/demo/livewire', 'Counter component', 'A component with a method and property updates.', 'livewire', look: [['Livewire', 'Click the buttons on the page, then open the update request.']]),
                self::demo('GET', '/demo/alarms/livewire-exception', 'Component exception', 'A component that throws before rendering and handles it itself.', 'livewire', [['Livewire component threw', 'bad']]),
            ]),
            self::lens('filament', 'Filament', 'Packages', 'actions, tables and notifications', [
                self::demo('GET', '/admin/posts', 'Failing and throwing actions', 'Click Refuse (calls failure()) or Explode (throws) on the posts list. Log in as ada@example.com, password "password".', 'filament', [['Filament action failed', 'bad'], ['Filament action threw', 'bad']], [['Filament', 'Open the Livewire update request of the click.']]),
                self::demo('GET', '/admin/sensor-readings', 'Large table', 'A table that shows 600 records without pagination.', 'filament', [['Filament table without pagination', 'warn']]),
            ]),
            self::lens('inertia', 'Inertia', 'Packages', 'pages and props', [
                self::demo('GET', '/demo/inertia', 'Inertia page', 'Inertia::render() with props. Partial reloads need an X-Inertia request.', 'inertia', [['Large Inertia prop', 'warn']]),
                self::demo('GET', '/demo/alarms/inertia-large-page', 'Large page', 'Six props of about 38 KB each: no single prop is large, the page is.', 'inertia', [['Large Inertia page', 'warn']]),
                self::demo('GET', '/demo/alarms/inertia-shared-on-partial', 'Shared prop on a partial reload', 'Sends a partial reload with only an except list; the shared app prop comes along.', 'inertia', [['Shared prop on partial reload', 'warn']], [['Inertia', 'Send the request, then open its capture: the app prop is highlighted.']]),
            ]),
            self::lens('request', 'Folio', 'Packages', 'page routes', [
                self::demo('GET', '/folio/users/42', 'Folio page', 'A Folio page with a route parameter.', 'request'),
            ]),
            self::lens('features', 'Pennant', 'Packages', 'feature flags', [
                self::demo('GET', '/demo/pennant', 'Feature flags', 'Checks two defined features and one undefined feature.', 'features', [['Undefined feature', 'warn']]),
            ]),
            self::lens('response_cache', 'Response cache', 'Packages', 'spatie/laravel-responsecache', [
                self::demo('GET', '/demo/response-cache', 'Cached response', 'Visit twice: the first is a miss, the second a hit.', 'response_cache', [['Served from the response cache', 'warn'], ['Middleware short-circuited', 'warn']]),
                self::demo('GET', '/demo/response-cache/clear', 'Clear the cache', 'Clears the response cache.', 'response_cache'),
            ]),
            self::lens('media', 'Media library', 'Packages', 'spatie/laravel-medialibrary', [
                self::demo('GET', '/demo/media', 'Add media', 'Adds a generated image to a model and runs a conversion.', 'media', [['Conversion during the request', 'warn']]),
            ]),
            self::lens('ai', 'AI', 'Packages', 'laravel/ai agents and tools', [
                self::demo('GET', '/demo/ai', 'Agent call', 'Prompts a faked agent, no network needed.', 'ai'),
                self::demo('GET', '/demo/alarms/ai-agent-failed', 'Failed agent', 'An agent whose model call throws.', 'ai', [['Agent failed', 'bad']]),
                self::demo('GET', '/demo/alarms/ai-tool-failed', 'Failed tool', 'The model asks for a tool that throws, which ends the run.', 'ai', [['Tool failed', 'bad'], ['Agent failed', 'bad']]),
            ]),
            self::lens('prism', 'Prism', 'Packages', 'prism-php/prism calls', [
                self::demo('GET', '/demo/prism', 'Prism text call', 'Prompts a faked Prism provider, no network needed.', 'prism'),
                self::demo('GET', '/demo/alarms/prism-failed', 'Failed Prism call', 'A text call to a model that is always overloaded.', 'prism', [['Prism call failed', 'bad']]),
                self::demo('GET', '/demo/alarms/prism-tool-failed', 'Failed Prism tool', 'A Prism tool that throws when the provider runs it.', 'prism', [['Prism tool failed', 'bad']]),
            ]),
            self::lens('mcp', 'MCP', 'Packages', 'your own MCP servers', [
                self::demo('GET', '/demo/mcp', 'MCP tool call', 'Calls a tool on a small web MCP server.', 'mcp'),
                self::demo('GET', '/demo/alarms/mcp-error', 'MCP error', 'Calls a tool the demo server does not have; the answer is an error.', 'mcp', [['MCP call returned an error', 'bad']], [['MCP', 'Send the request, then open its capture.']]),
            ]),
        ];
    }

    /**
     * The demo behind a path, with the lens it belongs to and its neighbours there.
     *
     * @return array{demo: Demo, lens: Lens, previous: Demo|null, next: Demo|null}|null
     */
    public static function find(string $path): ?array
    {
        $path = '/'.ltrim($path, '/');

        if ($path === self::showcase()['path']) {
            $lens = ['id' => 'showcase', 'name' => 'Everything', 'category' => 'Data', 'blurb' => '', 'demos' => [self::showcase()]];

            return ['demo' => self::showcase(), 'lens' => $lens, 'previous' => null, 'next' => null];
        }

        foreach (self::lenses() as $lens) {
            foreach ($lens['demos'] as $index => $demo) {
                // Query strings are part of a few demo links, not of the path the page sees.
                if (strtok($demo['path'], '?') !== $path) {
                    continue;
                }

                return [
                    'demo' => $demo,
                    'lens' => $lens,
                    'previous' => $lens['demos'][$index - 1] ?? null,
                    'next' => $lens['demos'][$index + 1] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Every alarm with the demos that raise it, errors first.
     *
     * @return array{bad: list<array{label: string, lens: string, demo: Demo}>, warn: list<array{label: string, lens: string, demo: Demo}>}
     */
    public static function alarms(): array
    {
        $alarms = ['bad' => [], 'warn' => []];

        foreach (self::lenses() as $lens) {
            foreach ($lens['demos'] as $demo) {
                foreach ($demo['alarms'] ?? [] as [$label, $tone]) {
                    if (! isset($alarms[$tone])) {
                        continue;
                    }

                    $alarms[$tone][] = ['label' => $label, 'lens' => $lens['name'], 'demo' => $demo];
                }
            }
        }

        return $alarms;
    }

    /**
     * @param  list<Demo>  $demos
     * @return Lens
     */
    private static function lens(string $id, string $name, string $category, string $blurb, array $demos): array
    {
        return ['id' => $id, 'name' => $name, 'category' => $category, 'blurb' => $blurb, 'demos' => $demos];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $alarms
     * @param  list<array{0: string, 1: string}>  $look
     * @return Demo
     */
    private static function demo(string $method, string $path, string $name, string $text, string $tab, array $alarms = [], array $look = []): array
    {
        return ['method' => $method, 'path' => $path, 'name' => $name, 'text' => $text, 'tab' => $tab, 'alarms' => $alarms, 'look' => $look];
    }
}
