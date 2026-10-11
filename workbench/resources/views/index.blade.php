@php
    $groups = [
        'Database' => [
            ['GET', '/demo/database/n-plus-one', 'N+1', 'Loads posts, then each post\'s comments lazily in a loop.'],
            ['GET', '/demo/database/slow', 'Slow query', 'A recursive query that takes long enough to be flagged as slow.'],
            ['GET', '/demo/database/duplicate', 'Duplicate query', 'Runs the same query three times.'],
            ['GET', '/demo/database/rollback', 'Rolled back transaction', 'Inserts a post inside a transaction that is rolled back.'],
        ],
        'Eloquent' => [
            ['GET', '/demo/eloquent', 'Model changes', 'Creates, updates and deletes a post.'],
        ],
        'Cache' => [
            ['GET', '/demo/cache', 'Hits, misses and writes', 'A miss, a write, a hit and one key read repeatedly.'],
        ],
        'Views' => [
            ['GET', '/demo/views', 'Components and partials', 'Renders a Blade component in a loop and a partial per post.'],
        ],
        'Validation' => [
            ['GET', '/demo/validation', 'Failing form request', 'A form that posts invalid input to a form request and shows the errors.'],
            ['GET', '/demo/validation/inline?name=Ada&email=ada@example.com', 'Passing inline validation', 'Validates query input with $request->validate().'],
        ],
        'Session' => [
            ['GET', '/demo/session', 'Write and flash', 'Puts, increments and flashes session values.'],
        ],
        'Auth' => [
            ['POST', '/demo/auth/login', 'Log in', 'Logs in ada@example.com with Auth::login().'],
            ['POST', '/demo/auth/failed', 'Failed attempt', 'Auth::attempt() with a wrong password.'],
            ['POST', '/demo/auth/logout', 'Log out', 'Logs the current user out.'],
            ['GET', '/demo/auth/token', 'Sanctum token', 'Creates a token for Ada and lets you call an auth:sanctum route with it.'],
        ],
        'Authorization' => [
            ['GET', '/demo/authorization/allowed', 'Allowed gate', 'A gate check that passes.'],
            ['GET', '/demo/authorization/denied', 'Denied gate', 'A gate check that fails with a 403.'],
        ],
        'Events' => [
            ['GET', '/demo/events', 'Custom event', 'Dispatches PostPublished, handled by a listener.'],
        ],
        'Queue' => [
            ['GET', '/demo/queue', 'Dispatch a job', 'Runs a job on the sync queue.'],
            ['GET', '/demo/queue/chain', 'Job dispatching a job', 'A job that dispatches another job.'],
            ['GET', '/demo/queue/failing', 'Failing job', 'A job that throws; the failure is caught and shown.'],
        ],
        'Mail and notifications' => [
            ['GET', '/demo/mail', 'Mailable and notification', 'Sends a mailable and a mail notification to the log mailer.'],
        ],
        'HTTP client' => [
            ['GET', '/demo/http', 'Faked remote API', 'Calls a faked API, including a call that fails with a 500.'],
        ],
        'Filesystem' => [
            ['GET', '/demo/filesystem', 'Put, get and delete', 'Writes, reads and deletes files on the demo disk.'],
        ],
        'Log' => [
            ['GET', '/demo/log', 'Log entries', 'Writes info, warning and error entries.'],
        ],
        'Exceptions' => [
            ['GET', '/demo/exceptions/reported', 'Reported exception', 'Reports an exception with report() and carries on.'],
            ['GET', '/demo/exceptions/thrown', 'Thrown exception', 'Throws an exception, resulting in a 500.'],
        ],
        'Dumps' => [
            ['GET', '/demo/dump', 'dump()', 'Dumps a value.'],
        ],
        'Middleware' => [
            ['GET', '/demo/middleware', 'Slow middleware', 'A route behind a middleware that sleeps 150ms.'],
        ],
        'Artisan' => [
            ['GET', '/demo/artisan', 'Artisan::call()', 'Calls the demo:greet command. Run `vendor/bin/testbench demo:greet` for a command capture.'],
        ],
        'Livewire' => [
            ['GET', '/demo/livewire', 'Counter component', 'A component with a method and property updates.'],
        ],
        'Inertia' => [
            ['GET', '/demo/inertia', 'Inertia page', 'Inertia::render() with props. Partial reloads need an X-Inertia request.'],
        ],
        'Folio' => [
            ['GET', '/folio/users/42', 'Folio page', 'A Folio page with a route parameter.'],
        ],
        'Pennant' => [
            ['GET', '/demo/pennant', 'Feature flags', 'Checks two defined features and one undefined feature.'],
        ],
        'Response cache' => [
            ['GET', '/demo/response-cache', 'Cached response', 'Visit twice: the first is a miss, the second a hit.'],
            ['GET', '/demo/response-cache/clear', 'Clear the cache', 'Clears the response cache.'],
        ],
        'Media library' => [
            ['GET', '/demo/media', 'Add media', 'Adds a generated image to a model and runs a conversion.'],
        ],
        'laravel/ai' => [
            ['GET', '/demo/ai', 'Agent call', 'Prompts a faked agent, no network needed.'],
        ],
        'MCP' => [
            ['GET', '/demo/mcp', 'MCP tool call', 'Calls a tool on a small web MCP server.'],
        ],
        'Custom spans' => [
            ['GET', '/demo/spans', 'Monocle::span() and measure()', 'Times work with custom spans.'],
        ],
    ];
@endphp

@extends('layout', ['title' => 'Monocle demo'])

@section('content')
    <h1>Monocle demo</h1>
    <p>
        Trigger any demo below, then open the <a href="/_monocle" target="_blank">dashboard</a> to inspect the capture.
        Seeded users have the password <code>password</code>, for example <code>ada@example.com</code>.
    </p>
    <p>{{ auth()->check() ? 'Logged in as '.auth()->user()->email.'.' : 'Not logged in.' }}</p>

    @foreach ($groups as $group => $demos)
        <h2>{{ $group }}</h2>
        <ul>
            @foreach ($demos as [$method, $url, $label, $description])
                <li>
                    @if ($method === 'POST')
                        <form method="POST" action="{{ $url }}">
                            @csrf
                            <button type="submit">{{ $label }}</button>
                        </form>
                    @else
                        <a href="{{ $url }}">{{ $label }}</a>
                    @endif
                    <span>{{ $description }}</span>
                </li>
            @endforeach
        </ul>
    @endforeach
@endsection
