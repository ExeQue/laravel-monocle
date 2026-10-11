<?php

declare(strict_types=1);

use ExeQue\Monocle\Facades\Monocle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Inertia\Inertia;
use Laravel\Pennant\Feature;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Spatie\ResponseCache\Facades\ResponseCache;
use Spatie\ResponseCache\Middlewares\CacheResponse;
use Workbench\App\Ai\Greeter;
use Workbench\App\Events\PaymentCharged;
use Workbench\App\Events\PostPublished;
use Workbench\App\Http\Middleware\SlowMiddleware;
use Workbench\App\Http\Requests\ContactRequest;
use Workbench\App\Jobs\BrokenJob;
use Workbench\App\Jobs\Countdown;
use Workbench\App\Jobs\ImportBatch;
use Workbench\App\Jobs\ProcessReport;
use Workbench\App\Mail\OrderShipped;
use Workbench\App\Models\Gallery;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;
use Workbench\App\Notifications\WelcomeNotification;
use Workbench\App\Rules\Slug;

$result = fn (string $title, mixed $output = null): View => view('result', ['title' => $title, 'output' => $output]);

Route::view('/', 'index')->name('home');

Route::prefix('demo')->name('demo.')->group(function () use ($result): void {
    Route::get('showcase', function (Request $request): View {
        $author = Monocle::measure('load author', fn (): User => Cache::remember(
            'demo:author',
            60,
            fn (): User => User::query()->where('email', 'ada@example.com')->firstOrFail(),
        ));

        Monocle::measure('build feed', function (): void {
            // One query per post for its comments: an N+1.
            Post::query()->latest()->limit(5)->get()->each(fn (Post $post): int => $post->comments->count());

            // The same lookup three times: duplicate queries.
            foreach (range(1, 3) as $ignored) {
                DB::table('users')->where('email', 'ada@example.com')->first();
            }

            // A query slow enough to be flagged.
            DB::selectOne('WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 1000000) SELECT count(*) AS total FROM n');

            // A cache store that answers slowly.
            Cache::store('slow')->remember('demo:exchange-rates', 60, fn (): array => ['EUR' => 7.46, 'USD' => 6.89]);

            // The same cache key read over and over.
            foreach (range(1, 8) as $ignored) {
                Cache::get('demo:author');
            }
        });

        Monocle::measure('validate draft', fn (): array => Validator::make(
            ['title' => 'Monocle 1.0', 'slug' => 'monocle-1-0', 'body' => 'Profiling for agents.', 'api_token' => 'tok_4c8e1a7f9b2d'],
            [
                'title' => ['required', 'string', 'max:120'],
                'slug' => ['required', new Slug(max: 40)],
                'body' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                    if (str_contains((string) $value, 'lorem ipsum')) {
                        $fail('The :attribute still has placeholder text.');
                    }
                }],
                'api_token' => ['nullable', 'string'],
            ],
        )->validate());

        Gate::authorize('read-docs');
        Feature::active('new-dashboard');
        Feature::active('new-chekout');

        $publish = Monocle::span('publish post')->start();

        $post = DB::transaction(function () use ($author): Post {
            $post = Post::query()->create(['user_id' => $author->id, 'title' => 'Monocle 1.0', 'body' => 'Draft.']);
            $post->update(['body' => 'Profiling for agents.']);
            $post->comments()->create(['body' => 'First!']);

            return $post;
        });

        PostPublished::dispatch($post);
        ProcessReport::dispatch('post-'.$post->id);
        // Queued for the worker (composer queue, or docker compose up queue); it becomes its own capture.
        ProcessReport::dispatch('post-'.$post->id.'-digest')->onConnection('database');
        Mail::to($author)->send(new OrderShipped());
        $author->notify(new WelcomeNotification());

        $publish->stop(['post' => $post->id]);

        $enrich = Monocle::span('enrich post')->start();

        Http::fake([
            'api.example.test/posts/*' => Http::response(['views' => 1204], 200),
            'social.example.test/*' => Http::sequence()->push(['shared' => true], 201)->push(['shared' => true], 201)->push(['error' => 'Rate limited'], 429),
        ]);
        $stats = Http::withHeaders(['X-Api-Key' => 'live_8f2c41d09a7e4b3c'])
            ->get('https://api.example.test/posts/'.$post->id.'/stats', ['access_token' => 'tok_5d1e9c0b7a2f'])
            ->json();
        Http::pool(fn ($pool) => [
            $pool->as('mastodon')->withToken('mastodon_3a9f7c2e1b8d4f60')->post('https://social.example.test/mastodon/share', ['post' => $post->id]),
            $pool->as('bluesky')->withToken('bsky_6e0d2b9a4c7f1e35')->post('https://social.example.test/bluesky/share', ['post' => $post->id]),
            $pool->as('linkedin')->withToken('li_9c4b1f7e2a0d6b83')->post('https://social.example.test/linkedin/share', [
                'post' => $post->id,
                'client_id' => 'monocle-demo',
                'client_secret' => 'cs_2f8e0a6d4b9c1e7f',
                'signature' => 'sig_7b3e9d1a5c0f2e84',
            ]),
            $pool->as('webhook')->connectTimeout(2)->post('https://hooks.monocle-demo.invalid/published', ['post' => $post->id]),
        ]);

        // A real call, so its transfer timing (dns, connect, tls, wait, download) is recorded.
        try {
            Http::connectTimeout(2)->timeout(5)->get('https://httpbin.org/get', ['source' => 'monocle']);
        } catch (ConnectionException) {
            // Offline: the failed connection is recorded instead.
        }

        try {
            Http::connectTimeout(2)->get('https://expired.badssl.com/');
        } catch (ConnectionException) {
            // The certificate check is expected to fail; the call is still recorded.
        }

        Greeter::fake(['A short, friendly summary.']);
        $summary = (string) (new Greeter())->prompt('Summarize: '.$post->body);

        Storage::disk('demo')->put('posts/'.$post->id.'.txt', $summary);

        $enrich->stop(['views' => $stats['views']]);

        PaymentCharged::dispatch(4900, 'DKK', $author->name, 'visa •••• 4242', 'paid');
        PaymentCharged::dispatch(1900, 'DKK', 'Grace Hopper', 'mastercard •••• 4444', 'declined', 'insufficient funds');
        PaymentCharged::dispatch(2500, 'DKK', 'Alan Turing', 'amex •••• 0005', 'failed', 'gateway timeout');

        Monocle::mark('post ready', ['post' => $post->id]);

        $request->session()->put('demo.last_post', $post->id);
        Log::info('Post published.', ['post' => $post->id]);
        report(new RuntimeException('Search index unavailable, retrying later.', 0, new ConnectionException(
            'Could not connect to search.internal:9200',
            0,
            new RuntimeException('Connection refused'),
        )));
        Artisan::call('demo:greet', ['name' => $author->name]);

        $post->comments()->delete();
        $post->delete();
        Storage::disk('demo')->delete('posts/'.$post->id.'.txt');

        return view('views-demo', [
            'tags' => array_map(fn (int $i): string => 'tag '.$i, range(1, 15)),
            'posts' => Post::query()->withCount('comments')->limit(5)->get(),
        ]);
    })->middleware(SlowMiddleware::class)->name('showcase');

    Route::get('database/n-plus-one', function () use ($result): View {
        $counts = Post::query()->get()->mapWithKeys(fn (Post $post): array => [$post->title => $post->comments->count()]);

        return $result('N+1', $counts);
    })->name('database.n-plus-one');

    Route::get('database/slow', function () use ($result): View {
        $count = DB::selectOne('WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 1000000) SELECT count(*) AS total FROM n');

        return $result('Slow query', $count);
    })->name('database.slow');

    Route::get('database/duplicate', function () use ($result): View {
        foreach (range(1, 3) as $ignored) {
            $user = DB::table('users')->where('email', 'ada@example.com')->first();
        }

        return $result('Duplicate query', $user->name);
    })->name('database.duplicate');

    Route::get('database/rollback', function () use ($result): View {
        DB::beginTransaction();
        Post::query()->create(['user_id' => User::query()->value('id'), 'title' => 'Never saved', 'body' => 'Rolled back.']);
        DB::rollBack();

        return $result('Rolled back transaction', Post::query()->where('title', 'Never saved')->count().' posts titled "Never saved"');
    })->name('database.rollback');

    Route::get('eloquent', function () use ($result): View {
        $post = Post::query()->create(['user_id' => User::query()->value('id'), 'title' => 'Draft', 'body' => 'First version.']);
        $post->update(['title' => 'Published', 'body' => 'Second version.']);
        $post->delete();

        return $result('Model changes', 'Created, updated and deleted post #'.$post->id);
    })->name('eloquent');

    Route::get('cache', function () use ($result): View {
        $missed = Cache::get('demo:missing');
        Cache::put('demo:greeting', 'hello', 60);
        $hit = Cache::get('demo:greeting');
        $settings = Cache::remember('demo:settings', 60, fn (): array => ['theme' => 'dark']);

        foreach (range(1, 8) as $ignored) {
            Cache::get('demo:settings');
        }

        return $result('Cache', compact('missed', 'hit', 'settings'));
    })->name('cache');

    Route::get('views', fn (): View => view('views-demo', [
        'tags' => array_map(fn (int $i): string => 'tag '.$i, range(1, 15)),
        'posts' => Post::query()->withCount('comments')->limit(5)->get(),
    ]))->name('views');

    Route::view('validation', 'validation')->name('validation');
    Route::post('validation/form-request', fn (ContactRequest $request): View => $result('Validation passed', $request->validated()))->name('validation.form-request');
    Route::get('validation/inline', fn (Request $request): View => $result('Inline validation passed', $request->validate([
        'name' => ['required', 'string'],
        'email' => ['required', 'email'],
    ])))->name('validation.inline');

    Route::get('session', function (Request $request) use ($result): View {
        $request->session()->put('demo.last_visit', now()->toIso8601String());
        $request->session()->increment('demo.visits');
        $request->session()->flash('status', 'Flashed for the next request.');

        return $result('Session', $request->session()->get('demo'));
    })->name('session');

    Route::post('auth/login', function (Request $request) {
        Auth::login(User::query()->where('email', 'ada@example.com')->firstOrFail());
        $request->session()->regenerate();

        return redirect('/');
    })->name('auth.login');

    Route::post('auth/failed', fn (): View => $result('Failed attempt', Auth::attempt(['email' => 'ada@example.com', 'password' => 'wrong-password']) ? 'Logged in?' : 'Wrong password, as expected.'))->name('auth.failed');

    Route::post('auth/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    })->name('auth.logout');

    Route::get('auth/token', function (): View {
        $token = User::query()->where('email', 'ada@example.com')->firstOrFail()->createToken('demo')->plainTextToken;

        return view('fetch', [
            'title' => 'Sanctum token',
            'description' => 'A token was created for Ada. Send it to an auth:sanctum route; that request is a capture of its own.',
            'method' => 'GET',
            'url' => '/api/me',
            'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token],
        ]);
    })->name('auth.token');

    Route::get('authorization/allowed', function () use ($result): View {
        Gate::authorize('read-docs');

        return $result('Allowed gate', 'read-docs was allowed.');
    })->name('authorization.allowed');

    Route::get('authorization/denied', function () use ($result): View {
        Gate::authorize('delete-everything');

        return $result('Denied gate');
    })->name('authorization.denied');

    Route::get('events', function () use ($result): View {
        PostPublished::dispatch(Post::query()->firstOrFail());

        return $result('Custom event', 'PostPublished dispatched.');
    })->name('events');

    Route::get('queue', function () use ($result): View {
        ProcessReport::dispatch('monthly-sales');

        return $result('Dispatch a job', 'ProcessReport ran on the sync queue.');
    })->name('queue');

    Route::get('queue/database', function () use ($result): View {
        ProcessReport::dispatch('monthly-sales')->onConnection('database');

        return $result('Queue a job', 'ProcessReport is waiting on the database queue. Work the queue to run it as a capture of its own.');
    })->name('queue.database');

    Route::get('queue/countdown', function () use ($result): View {
        Countdown::dispatch(3)->onConnection('database');

        return $result('Count down', 'Countdown(3) is on the database queue; each run queues the next until 0. Work the queue to follow the trace.');
    })->name('queue.countdown');

    Route::get('queue/work', function () use ($result): View {
        // A separate process, like a real worker: each job it runs is a capture of its own.
        $run = Process::path(dirname(__DIR__, 2))->timeout(60)->run([PHP_BINARY, 'vendor/bin/testbench', 'queue:work', 'database', '--stop-when-empty']);

        return $result('Work the queue', trim($run->output().$run->errorOutput()) ?: 'The queue was empty.');
    })->name('queue.work');

    Route::get('process', function () use ($result): View {
        $version = Process::run([PHP_BINARY, '-v']);
        $failing = Process::run([PHP_BINARY, '-r', 'fwrite(STDERR, "broken"); exit(3);']);

        return $result('Run processes', strtok($version->output(), "\n").' - and one that exited with '.$failing->exitCode().'.');
    })->name('process');

    Route::get('queue/chain', function () use ($result): View {
        ImportBatch::dispatch();

        return $result('Job dispatching a job', 'ImportBatch dispatched ProcessReport.');
    })->name('queue.chain');

    Route::get('queue/failing', function () use ($result): View {
        try {
            BrokenJob::dispatch();
        } catch (RuntimeException $exception) {
            return $result('Failing job', $exception->getMessage());
        }

        return $result('Failing job', 'The job did not fail.');
    })->name('queue.failing');

    Route::get('mail', function () use ($result): View {
        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

        Mail::to($user)->send(new OrderShipped());
        $user->notify(new WelcomeNotification());

        return $result('Mail and notifications', 'Sent to the log mailer.');
    })->name('mail');

    Route::get('http', function () use ($result): View {
        Http::fake([
            'api.example.test/users/*' => Http::response(['name' => 'Ada'], 200),
            'api.example.test/broken' => Http::response(['error' => 'Server exploded'], 500),
        ]);

        return $result('HTTP client', [
            'user' => Http::get('https://api.example.test/users/1')->json(),
            'broken' => Http::get('https://api.example.test/broken')->status(),
        ]);
    })->name('http');

    Route::get('http/failures', function () use ($result): View {
        $outcomes = [];

        foreach ([
            'expired certificate' => 'https://expired.badssl.com/',
            'self-signed certificate' => 'https://self-signed.badssl.com/',
            'unknown host' => 'https://monocle-demo.invalid/',
            'no answer' => 'http://10.255.255.1/',
        ] as $label => $url) {
            try {
                $outcomes[$label] = Http::connectTimeout(2)->timeout(3)->get($url)->status();
            } catch (Throwable $exception) {
                $outcomes[$label] = class_basename($exception).': '.Str::limit($exception->getMessage(), 90);
            }
        }

        return $result('Failed connections', $outcomes);
    })->name('http.failures');

    Route::get('filesystem', function () use ($result): View {
        $disk = Storage::disk('demo');
        $disk->put('notes/hello.txt', 'Hello from the demo.');
        $contents = $disk->get('notes/hello.txt');
        $exists = $disk->exists('notes/missing.txt');
        $disk->delete('notes/hello.txt');

        return $result('Filesystem', compact('contents', 'exists'));
    })->name('filesystem');

    Route::get('log', function () use ($result): View {
        Log::info('A demo info entry.', ['user' => Auth::id()]);
        Log::warning('A demo warning entry.');
        Log::error('A demo error entry.', ['reason' => 'just testing']);

        return $result('Log', 'Wrote three log entries.');
    })->name('log');

    Route::get('exceptions/reported', function () use ($result): View {
        report(new RuntimeException('Reported but handled.'));

        return $result('Reported exception', 'The exception was reported and the request carried on.');
    })->name('exceptions.reported');

    Route::get('exceptions/thrown', fn () => throw new RuntimeException('Thrown on purpose.'))->name('exceptions.thrown');

    Route::get('dump', function () use ($result): View {
        ob_start();
        dump(['user' => 'Ada', 'roles' => ['admin', 'editor']]);

        return $result('dump()', 'Dumped a value.');
    })->name('dump');

    Route::get('middleware', fn (): View => $result('Slow middleware', 'Passed through SlowMiddleware.'))->middleware(SlowMiddleware::class)->name('middleware');

    Route::get('artisan', function () use ($result): View {
        Artisan::call('demo:greet', ['name' => 'Ada', '--shout' => true]);

        return $result('Artisan::call()', Artisan::output());
    })->name('artisan');

    Route::view('livewire', 'livewire-demo')->name('livewire');

    Route::get('inertia', fn () => Inertia::render('Dashboard', [
        'user' => ['name' => 'Ada'],
        'posts' => Post::query()->limit(3)->pluck('title'),
        'stats' => Inertia::defer(fn (): array => ['posts' => Post::query()->count()]),
    ]))->name('inertia');

    Route::get('pennant', fn (): View => $result('Feature flags', [
        'new-dashboard' => Feature::active('new-dashboard'),
        'beta-checkout' => Feature::active('beta-checkout'),
        'undefined-feature' => Feature::active('undefined-feature'),
    ]))->name('pennant');

    Route::get('response-cache', fn (): View => $result('Cached response', 'Rendered at '.now()->toTimeString().'. Reload to get it from the cache.'))->middleware(CacheResponse::class)->name('response-cache');

    Route::get('response-cache/clear', function () use ($result): View {
        ResponseCache::clear();

        return $result('Clear the cache', 'The response cache was cleared.');
    })->name('response-cache.clear');

    Route::get('media', function () use ($result): View {
        $path = tempnam(sys_get_temp_dir(), 'monocle').'.png';
        $image = imagecreatetruecolor(120, 80);
        imagefill($image, 0, 0, imagecolorallocate($image, 37, 99, 235));
        imagepng($image, $path);

        $media = Gallery::query()->firstOrCreate(['name' => 'Demo gallery'])
            ->addMedia($path)
            ->usingFileName('square.png')
            ->toMediaCollection('photos');

        return $result('Add media', ['media' => $media->id, 'conversions' => $media->getGeneratedConversions()]);
    })->name('media');

    Route::get('ai', function () use ($result): View {
        Greeter::fake(['Hello Ada, lovely to meet you!']);

        return $result('Agent call', (string) (new Greeter())->prompt('Say hi to Ada'));
    })->name('ai');

    Route::get('prism', function () use ($result): View {
        Prism::fake([
            TextResponseFake::make()->withText('Hello Ada, lovely to meet you!')->withUsage(new Usage(12, 9)),
        ]);

        return $result('Prism text call', Prism::text()->using('openai', 'gpt-4o')->withPrompt('Say hi to Ada')->asText()->text);
    })->name('prism');

    Route::get('mcp', fn (): View => view('fetch', [
        'title' => 'MCP tool call',
        'description' => 'Calls the echo tool on the demo MCP server. The MCP request is a capture of its own.',
        'method' => 'POST',
        'url' => '/api/mcp/demo',
        'headers' => ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json'],
        'body' => [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'echo', 'arguments' => ['text' => 'hello monocle']],
        ],
    ]))->name('mcp');

    Route::get('spans', function () use ($result): View {
        $span = Monocle::span('prepare report')->start();
        usleep(30_000);
        $span->stop();

        $total = Monocle::measure('sum posts', fn (): int => Post::query()->count(), ['source' => 'demo']);

        return $result('Custom spans', ['posts' => $total]);
    })->name('spans');
});
