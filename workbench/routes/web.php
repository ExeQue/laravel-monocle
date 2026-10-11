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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Inertia\Inertia;
use Laravel\Pennant\Feature;
use Spatie\ResponseCache\Facades\ResponseCache;
use Spatie\ResponseCache\Middlewares\CacheResponse;
use Workbench\App\Ai\Greeter;
use Workbench\App\Events\PostPublished;
use Workbench\App\Http\Middleware\SlowMiddleware;
use Workbench\App\Http\Requests\ContactRequest;
use Workbench\App\Jobs\BrokenJob;
use Workbench\App\Jobs\ImportBatch;
use Workbench\App\Jobs\ProcessReport;
use Workbench\App\Mail\OrderShipped;
use Workbench\App\Models\Gallery;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;
use Workbench\App\Notifications\WelcomeNotification;

$result = fn (string $title, mixed $output = null): View => view('result', ['title' => $title, 'output' => $output]);

Route::view('/', 'index');

Route::prefix('demo')->group(function () use ($result): void {
    Route::get('showcase', function (Request $request): View {
        $author = Monocle::measure('load author', fn (): User => Cache::remember(
            'demo:author',
            60,
            fn (): User => User::query()->where('email', 'ada@example.com')->firstOrFail(),
        ));

        Monocle::measure('validate draft', fn (): array => Validator::make(
            ['title' => 'Monocle 1.0', 'body' => 'Profiling for agents.'],
            ['title' => ['required', 'string', 'max:120'], 'body' => ['required', 'string']],
        )->validate());

        Gate::authorize('read-docs');
        Feature::active('new-dashboard');

        $publish = Monocle::span('publish post')->start();

        $post = DB::transaction(function () use ($author): Post {
            $post = Post::query()->create(['user_id' => $author->id, 'title' => 'Monocle 1.0', 'body' => 'Draft.']);
            $post->update(['body' => 'Profiling for agents.']);
            $post->comments()->create(['body' => 'First!']);

            return $post;
        });

        PostPublished::dispatch($post);
        ProcessReport::dispatch('post-'.$post->id);
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

        try {
            Http::connectTimeout(2)->get('https://expired.badssl.com/');
        } catch (ConnectionException) {
            // The certificate check is expected to fail; the call is still recorded.
        }

        Greeter::fake(['A short, friendly summary.']);
        $summary = (string) (new Greeter())->prompt('Summarize: '.$post->body);

        Storage::disk('demo')->put('posts/'.$post->id.'.txt', $summary);

        $enrich->stop(['views' => $stats['views']]);

        Monocle::mark('post ready', ['post' => $post->id]);

        $request->session()->put('demo.last_post', $post->id);
        Log::info('Post published.', ['post' => $post->id]);
        report(new RuntimeException('Search index unavailable, retrying later.'));
        Artisan::call('demo:greet', ['name' => $author->name]);

        $post->comments()->delete();
        $post->delete();
        Storage::disk('demo')->delete('posts/'.$post->id.'.txt');

        return view('views-demo', [
            'tags' => array_map(fn (int $i): string => 'tag '.$i, range(1, 15)),
            'posts' => Post::query()->withCount('comments')->limit(5)->get(),
        ]);
    });

    Route::get('database/n-plus-one', function () use ($result): View {
        $counts = Post::query()->get()->mapWithKeys(fn (Post $post): array => [$post->title => $post->comments->count()]);

        return $result('N+1', $counts);
    });

    Route::get('database/slow', function () use ($result): View {
        $count = DB::selectOne('WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 1000000) SELECT count(*) AS total FROM n');

        return $result('Slow query', $count);
    });

    Route::get('database/duplicate', function () use ($result): View {
        foreach (range(1, 3) as $ignored) {
            $user = DB::table('users')->where('email', 'ada@example.com')->first();
        }

        return $result('Duplicate query', $user->name);
    });

    Route::get('database/rollback', function () use ($result): View {
        DB::beginTransaction();
        Post::query()->create(['user_id' => User::query()->value('id'), 'title' => 'Never saved', 'body' => 'Rolled back.']);
        DB::rollBack();

        return $result('Rolled back transaction', Post::query()->where('title', 'Never saved')->count().' posts titled "Never saved"');
    });

    Route::get('eloquent', function () use ($result): View {
        $post = Post::query()->create(['user_id' => User::query()->value('id'), 'title' => 'Draft', 'body' => 'First version.']);
        $post->update(['title' => 'Published', 'body' => 'Second version.']);
        $post->delete();

        return $result('Model changes', 'Created, updated and deleted post #'.$post->id);
    });

    Route::get('cache', function () use ($result): View {
        $missed = Cache::get('demo:missing');
        Cache::put('demo:greeting', 'hello', 60);
        $hit = Cache::get('demo:greeting');
        $settings = Cache::remember('demo:settings', 60, fn (): array => ['theme' => 'dark']);

        foreach (range(1, 8) as $ignored) {
            Cache::get('demo:settings');
        }

        return $result('Cache', compact('missed', 'hit', 'settings'));
    });

    Route::get('views', fn (): View => view('views-demo', [
        'tags' => array_map(fn (int $i): string => 'tag '.$i, range(1, 15)),
        'posts' => Post::query()->withCount('comments')->limit(5)->get(),
    ]));

    Route::view('validation', 'validation');
    Route::post('validation/form-request', fn (ContactRequest $request): View => $result('Validation passed', $request->validated()));
    Route::get('validation/inline', fn (Request $request): View => $result('Inline validation passed', $request->validate([
        'name' => ['required', 'string'],
        'email' => ['required', 'email'],
    ])));

    Route::get('session', function (Request $request) use ($result): View {
        $request->session()->put('demo.last_visit', now()->toIso8601String());
        $request->session()->increment('demo.visits');
        $request->session()->flash('status', 'Flashed for the next request.');

        return $result('Session', $request->session()->get('demo'));
    });

    Route::post('auth/login', function (Request $request) {
        Auth::login(User::query()->where('email', 'ada@example.com')->firstOrFail());
        $request->session()->regenerate();

        return redirect('/');
    });

    Route::post('auth/failed', fn (): View => $result('Failed attempt', Auth::attempt(['email' => 'ada@example.com', 'password' => 'wrong-password']) ? 'Logged in?' : 'Wrong password, as expected.'));

    Route::post('auth/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    });

    Route::get('auth/token', function (): View {
        $token = User::query()->where('email', 'ada@example.com')->firstOrFail()->createToken('demo')->plainTextToken;

        return view('fetch', [
            'title' => 'Sanctum token',
            'description' => 'A token was created for Ada. Send it to an auth:sanctum route; that request is a capture of its own.',
            'method' => 'GET',
            'url' => '/api/me',
            'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token],
        ]);
    });

    Route::get('authorization/allowed', function () use ($result): View {
        Gate::authorize('read-docs');

        return $result('Allowed gate', 'read-docs was allowed.');
    });

    Route::get('authorization/denied', function () use ($result): View {
        Gate::authorize('delete-everything');

        return $result('Denied gate');
    });

    Route::get('events', function () use ($result): View {
        PostPublished::dispatch(Post::query()->firstOrFail());

        return $result('Custom event', 'PostPublished dispatched.');
    });

    Route::get('queue', function () use ($result): View {
        ProcessReport::dispatch('monthly-sales');

        return $result('Dispatch a job', 'ProcessReport ran on the sync queue.');
    });

    Route::get('queue/chain', function () use ($result): View {
        ImportBatch::dispatch();

        return $result('Job dispatching a job', 'ImportBatch dispatched ProcessReport.');
    });

    Route::get('queue/failing', function () use ($result): View {
        try {
            BrokenJob::dispatch();
        } catch (RuntimeException $exception) {
            return $result('Failing job', $exception->getMessage());
        }

        return $result('Failing job', 'The job did not fail.');
    });

    Route::get('mail', function () use ($result): View {
        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

        Mail::to($user)->send(new OrderShipped());
        $user->notify(new WelcomeNotification());

        return $result('Mail and notifications', 'Sent to the log mailer.');
    });

    Route::get('http', function () use ($result): View {
        Http::fake([
            'api.example.test/users/*' => Http::response(['name' => 'Ada'], 200),
            'api.example.test/broken' => Http::response(['error' => 'Server exploded'], 500),
        ]);

        return $result('HTTP client', [
            'user' => Http::get('https://api.example.test/users/1')->json(),
            'broken' => Http::get('https://api.example.test/broken')->status(),
        ]);
    });

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
    });

    Route::get('filesystem', function () use ($result): View {
        $disk = Storage::disk('demo');
        $disk->put('notes/hello.txt', 'Hello from the demo.');
        $contents = $disk->get('notes/hello.txt');
        $exists = $disk->exists('notes/missing.txt');
        $disk->delete('notes/hello.txt');

        return $result('Filesystem', compact('contents', 'exists'));
    });

    Route::get('log', function () use ($result): View {
        Log::info('A demo info entry.', ['user' => Auth::id()]);
        Log::warning('A demo warning entry.');
        Log::error('A demo error entry.', ['reason' => 'just testing']);

        return $result('Log', 'Wrote three log entries.');
    });

    Route::get('exceptions/reported', function () use ($result): View {
        report(new RuntimeException('Reported but handled.'));

        return $result('Reported exception', 'The exception was reported and the request carried on.');
    });

    Route::get('exceptions/thrown', fn () => throw new RuntimeException('Thrown on purpose.'));

    Route::get('dump', function () use ($result): View {
        ob_start();
        dump(['user' => 'Ada', 'roles' => ['admin', 'editor']]);

        return $result('dump()', 'Dumped a value.');
    });

    Route::get('middleware', fn (): View => $result('Slow middleware', 'Passed through SlowMiddleware.'))->middleware(SlowMiddleware::class);

    Route::get('artisan', function () use ($result): View {
        Artisan::call('demo:greet', ['name' => 'Ada', '--shout' => true]);

        return $result('Artisan::call()', Artisan::output());
    });

    Route::view('livewire', 'livewire-demo');

    Route::get('inertia', fn () => Inertia::render('Dashboard', [
        'user' => ['name' => 'Ada'],
        'posts' => Post::query()->limit(3)->pluck('title'),
        'stats' => Inertia::defer(fn (): array => ['posts' => Post::query()->count()]),
    ]));

    Route::get('pennant', fn (): View => $result('Feature flags', [
        'new-dashboard' => Feature::active('new-dashboard'),
        'beta-checkout' => Feature::active('beta-checkout'),
        'undefined-feature' => Feature::active('undefined-feature'),
    ]));

    Route::get('response-cache', fn (): View => $result('Cached response', 'Rendered at '.now()->toTimeString().'. Reload to get it from the cache.'))->middleware(CacheResponse::class);

    Route::get('response-cache/clear', function () use ($result): View {
        ResponseCache::clear();

        return $result('Clear the cache', 'The response cache was cleared.');
    });

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
    });

    Route::get('ai', function () use ($result): View {
        Greeter::fake(['Hello Ada, lovely to meet you!']);

        return $result('Agent call', (string) (new Greeter())->prompt('Say hi to Ada'));
    });

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
    ]));

    Route::get('spans', function () use ($result): View {
        $span = Monocle::span('prepare report')->start();
        usleep(30_000);
        $span->stop();

        $total = Monocle::measure('sum posts', fn (): int => Post::query()->count(), ['source' => 'demo']);

        return $result('Custom spans', ['posts' => $total]);
    });
});
