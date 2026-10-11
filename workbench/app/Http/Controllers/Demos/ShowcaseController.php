<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos;

use Closure;
use ExeQue\Monocle\Facades\Monocle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Pennant\Feature;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool as PrismTool;
use RuntimeException;
use Workbench\App\Ai\Greeter;
use Workbench\App\Ai\PackingAdvisor;
use Workbench\App\Ai\TripPlanner;
use Workbench\App\Events\PaymentCharged;
use Workbench\App\Events\PostPublished;
use Workbench\App\Exceptions\PaymentDeclinedException;
use Workbench\App\Jobs\ProcessReport;
use Workbench\App\Mail\OrderShipped;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;
use Workbench\App\Notifications\WelcomeNotification;
use Workbench\App\Rules\Slug;

final class ShowcaseController
{
    public function __invoke(Request $request): View
    {
        $author = $this->loadAuthor();
        $this->buildFeed();
        $this->validateDraft();
        $this->checkAccessAndFeatures();
        $post = $this->publishPost($author);
        $this->enrichPost($post);
        $this->chargePayments($author);

        Monocle::mark('post ready', ['post' => $post->id]);

        $this->recordSideEffects($request, $author, $post);
        $this->cleanUp($post);

        return view('views-demo', [
            'tags' => array_map(fn (int $i): string => 'tag '.$i, range(1, 15)),
            'posts' => Post::query()->withCount('comments')->limit(5)->get(),
        ]);
    }

    private function loadAuthor(): User
    {
        return Monocle::measure('load author', fn (): User => Cache::remember(
            'demo:author',
            60,
            fn (): User => User::query()->where('email', 'ada@example.com')->firstOrFail(),
        ));
    }

    private function buildFeed(): void
    {
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
    }

    private function validateDraft(): void
    {
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
    }

    private function checkAccessAndFeatures(): void
    {
        Gate::authorize('read-docs');
        Feature::active('new-dashboard');
        Feature::active('new-chekout');
    }

    private function publishPost(User $author): Post
    {
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

        return $post;
    }

    private function enrichPost(Post $post): void
    {
        $enrich = Monocle::span('enrich post')->start();

        $stats = $this->callHttpServices($post);
        $summary = $this->callAgents($post);
        $this->callPrism();

        Storage::disk('demo')->put('posts/'.$post->id.'.txt', $summary);

        $enrich->stop(['views' => $stats['views']]);
    }

    /**
     * @return array{views: int}
     */
    private function callHttpServices(Post $post): array
    {
        Http::fake([
            'api.example.test/posts/*' => Http::response(['views' => 1204], 200),
            'social.example.test/*' => Http::sequence()->push(['shared' => true], 201)->push(['shared' => true], 201)->push(['error' => 'Rate limited'], 429),
        ]);
        $stats = Http::withHeaders(['X-Api-Key' => 'live_8f2c41d09a7e4b3c'])
            ->get('https://api.example.test/posts/'.$post->id.'/stats', ['access_token' => 'tok_5d1e9c0b7a2f'])
            ->json();
        $shared = Http::pool(fn ($pool) => [
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

        // The webhook host does not exist: the library's exception is reported and publishing carries on.
        if ($shared['webhook'] instanceof ConnectionException) {
            report($shared['webhook']);
        }

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

        return $stats;
    }

    private function callAgents(Post $post): string
    {
        Greeter::fake(['A short, friendly summary.']);
        $summary = (string) new Greeter()->prompt('Summarize: '.$post->body);

        // The model asks for a tool, a sub-agent and a tool that fails; laravel/ai runs them.
        PackingAdvisor::fake(['A raincoat and good shoes.']);
        TripPlanner::fake([
            new ToolCall('call_weather', 'weather', ['city' => 'Aarhus']),
            new ToolCall('call_packing', 'PackingAdvisor', ['task' => 'What should I pack for Aarhus?']),
            new ToolCall('call_nok', 'exchange_rate', ['currency' => 'NOK']),
            'Pack an umbrella; 100 DKK is about 13.40 EUR.',
        ]);

        try {
            new TripPlanner()->prompt('Plan a weekend in Aarhus.');
        } catch (RuntimeException) {
            // laravel/ai lets a failing tool end the run; the failed call and agent are recorded.
        }

        return $summary;
    }

    private function callPrism(): void
    {
        // The workbench Prism provider asks for this tool and runs it.
        $packingList = new PrismTool()->as('packing_list')->for('What to pack for a city')
            ->withStringParameter('city', 'The city')
            ->using(function (string $city): string {
                usleep(25_000);

                return "Raincoat for {$city}.";
            });

        Prism::text()->using('workbench', 'demo-model')->withPrompt('What should I pack?')->withTools([$packingList])->asText();
    }

    private function chargePayments(User $author): void
    {
        PaymentCharged::dispatch(4900, 'DKK', $author->name, 'visa •••• 4242', 'paid');
        PaymentCharged::dispatch(1900, 'DKK', 'Grace Hopper', 'mastercard •••• 4444', 'declined', 'insufficient funds');
        report(new PaymentDeclinedException('Card mastercard •••• 4444 was declined: insufficient funds.'));
        PaymentCharged::dispatch(2500, 'DKK', 'Alan Turing', 'amex •••• 0005', 'failed', 'gateway timeout');
    }

    private function recordSideEffects(Request $request, User $author, Post $post): void
    {
        $request->session()->put('demo.last_post', $post->id);
        // Objects, so the dump shows a model's attributes and a date as VarDumper draws them, and the author with
        // its password masked; the output is kept off the page.
        ob_start();
        dump($post->withoutRelations(), $post->created_at);
        dump($author);
        ob_end_clean();
        Log::info('Post published.', ['post' => $post->id]);
        report(new RuntimeException('Search index unavailable, retrying later.', 0, new ConnectionException(
            'Could not connect to search.internal:9200',
            0,
            new RuntimeException('Connection refused'),
        )));
        Artisan::call('demo:greet', ['name' => $author->name]);
    }

    private function cleanUp(Post $post): void
    {
        $post->comments()->delete();
        $post->delete();
        Storage::disk('demo')->delete('posts/'.$post->id.'.txt');
    }
}
