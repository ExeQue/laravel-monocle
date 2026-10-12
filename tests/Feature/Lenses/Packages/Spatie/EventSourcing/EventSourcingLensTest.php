<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Spatie\EventSourcing;

use Closure;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Spatie\EventSourcing\EventSourcingLens;
use ExeQue\Monocle\Lenses\Packages\Spatie\EventSourcing\TracedProjectionist;
use ExeQue\Monocle\Lenses\Packages\Spatie\EventSourcing\TracedStoredEventRepository;
use RuntimeException;
use Spatie\EventSourcing\Projectionist;
use Spatie\EventSourcing\StoredEvents\Repositories\StoredEventRepository;
use Tests\Fixtures\EventSourcing\AccountAggregate;
use Tests\Fixtures\EventSourcing\AccountOpened;
use Tests\Fixtures\EventSourcing\BalanceProjector;
use Tests\Fixtures\EventSourcing\QueuedProjector;
use Tests\Fixtures\EventSourcing\WelcomeReactor;

covers(EventSourcingLens::class, TracedProjectionist::class, TracedStoredEventRepository::class);

beforeEach(function (): void {
    foreach (['create_stored_events_table', 'create_snapshots_table'] as $table) {
        (include dirname(__DIR__, 6).'/vendor/spatie/laravel-event-sourcing/database/migrations/'.$table.'.php.stub')->up();
    }

    BalanceProjector::$added = [];
    QueuedProjector::$handled = 0;
});

/**
 * @return \ExeQue\Monocle\Data\Capture
 */
function recordEventSourcing(Closure $work): \ExeQue\Monocle\Data\Capture
{
    app(Eyepiece::class)->start(CaptureType::Request);
    $work();

    return app(Eyepiece::class)->finalize();
}

describe(EventSourcingLens::class, function (): void {
    it('records the events an aggregate stores, with the projectors that handled them', function (): void {
        $capture = recordEventSourcing(fn () => AccountAggregate::retrieve('acc-1')->addMoney(10)->addMoney(5)->persist());

        [$first, $second] = $capture->rows('event_sourcing');

        expect($first)->toMatchArray([
            'event' => \Tests\Fixtures\EventSourcing\MoneyAdded::class,
            'aggregate_uuid' => 'acc-1',
            'aggregate_version' => '1',
            'stored' => true,
            'queued' => true,
            'properties' => ['amount' => 10, 'token' => 'tok****'],
        ])
            ->and($first['stored_event_id'])->toBe(1)
            ->and(collect($first['handlers'])->map(fn (array $handler): array => [$handler['handler'], $handler['type'], $handler['mode'], $handler['status']])->all())->toBe([
                [BalanceProjector::class, 'projector', 'sync', 'ok'],
                [QueuedProjector::class, 'projector', 'queued', 'ok'],
            ])
            ->and($first['duration'])->toBeFloat()
            ->and($second['aggregate_version'])->toBe('2')
            ->and(BalanceProjector::$added)->toBe([10, 5])
            ->and(QueuedProjector::$handled)->toBe(2)
            ->and($capture->spansOfKind('event_sourcing'))->toHaveCount(4)
            ->and($capture->spansOfKind('event_sourcing.handler'))->toHaveCount(4)
            ->and($capture->rows('aggregates'))->toHaveCount(2)
            ->and($capture->rows('aggregates')[0])->toMatchArray(['action' => 'retrieved', 'aggregate' => AccountAggregate::class, 'uuid' => 'acc-1', 'events' => 0, 'snapshot' => false])
            ->and($capture->rows('aggregates')[1])->toMatchArray(['action' => 'persisted', 'aggregate' => AccountAggregate::class, 'uuid' => 'acc-1', 'events' => 2]);
    });

    it('records an aggregate rebuilt from its events, and from a snapshot', function (): void {
        AccountAggregate::retrieve('acc-2')->addMoney(1)->addMoney(2)->addMoney(3)->persist();

        $capture = recordEventSourcing(fn () => expect(AccountAggregate::retrieve('acc-2')->balance)->toBe(6));
        $lens = app(EventSourcingLens::class);
        $lens->configure(['snapshot_threshold' => 3]);

        expect($capture->rows('aggregates'))->toHaveCount(1)
            ->and($capture->rows('aggregates')[0])->toMatchArray(['action' => 'retrieved', 'events' => 3, 'snapshot' => false])
            ->and($lens->summarize($capture)->warnings)->toBe(['Aggregate '.AccountAggregate::class.' (acc-2) was rebuilt from 3 events without a snapshot.']);

        AccountAggregate::retrieve('acc-2')->snapshot();
        AccountAggregate::retrieve('acc-2')->addMoney(4)->persist();

        $capture = recordEventSourcing(fn () => expect(AccountAggregate::retrieve('acc-2')->balance)->toBe(10));

        expect($capture->rows('aggregates')[0])->toMatchArray(['action' => 'retrieved', 'events' => 1, 'snapshot' => true])
            ->and($lens->summarize($capture)->warnings)->toBe([]);
    });

    it('records an event stored on its own and a reactor that failed', function (): void {
        $capture = recordEventSourcing(function (): void {
            event(new AccountOpened('ada'));
            event(new AccountOpened('broken'));
        });

        [$ok, $broken] = $capture->rows('event_sourcing');
        $summary = app(EventSourcingLens::class)->summarize($capture);

        expect($ok)->toMatchArray(['event' => AccountOpened::class, 'aggregate_uuid' => null, 'stored' => true, 'queued' => false])
            ->and($ok['handlers'][0])->toMatchArray(['handler' => WelcomeReactor::class, 'type' => 'reactor', 'status' => 'ok', 'exception' => null])
            ->and($broken['handlers'][0]['status'])->toBe('failed')
            ->and($broken['handlers'][0]['exception']['message'])->toBe('welcome mail bounced')
            ->and($summary->errors)->toBe(['Reactor '.WelcomeReactor::class.' failed handling '.AccountOpened::class.': welcome mail bounced'])
            ->and($summary->totals)->toMatchArray(['event_sourcing_stored' => 2, 'event_sourcing_handled' => 2, 'event_sourcing_aggregates' => 0]);
    });

    it('passes a failure on when the projectionist does not catch them', function (): void {
        $projectionist = app(Projectionist::class);
        (fn () => $this->catchExceptions = false)->call($projectionist);

        expect(fn () => recordEventSourcing(fn () => event(new AccountOpened('broken'))))->toThrow(RuntimeException::class, 'welcome mail bounced');

        expect(app(Eyepiece::class)->finalize()->rows('event_sourcing')[0]['handlers'][0]['status'])->toBe('failed');
    });

    it('does what the package does while nothing is recorded', function (): void {
        app(Eyepiece::class)->discard();

        $aggregate = AccountAggregate::retrieve('acc-3')->addMoney(7)->persist();
        event(new AccountOpened('ada'));

        expect(AccountAggregate::retrieve('acc-3')->balance)->toBe(7)
            ->and(BalanceProjector::$added)->toBe([7])
            ->and(QueuedProjector::$handled)->toBe(1)
            ->and(app(Projectionist::class))->toBeInstanceOf(TracedProjectionist::class)
            ->and(app(StoredEventRepository::class))->toBeInstanceOf(TracedStoredEventRepository::class)
            ->and(app(StoredEventRepository::class)->countAllStartingFrom(0))->toBe(2);
    });

    it('passes the rest of the repository on', function (): void {
        $repository = app(StoredEventRepository::class);
        AccountAggregate::retrieve('acc-4')->addMoney(1)->persist();
        $stored = $repository->find(1);

        expect($stored->aggregate_uuid)->toBe('acc-4')
            ->and($repository->retrieveAllStartingFrom(0)->count())->toBe(1)
            ->and($repository->runForAllStartingFrom(0, fn () => null))->toBeTrue()
            ->and($repository->getLatestAggregateVersion('acc-4'))->toBe(1)
            ->and($repository->update($stored)->id)->toBe(1)
            ->and($repository->retrieveAll()->count())->toBe(1)
            ->and($repository->getStoredEventModel ?? null)->toBeNull();
    });

    it('shows a tab with the aggregates alongside, and an event folded out', function (): void {
        $presentation = app(EventSourcingLens::class)->presentation();

        expect($presentation->tabView)->toBe('monocle::tabs.event_sourcing')
            ->and($presentation->detailsView)->toBe('monocle::details.event-sourcing')
            ->and($presentation->includes)->toBe(['aggregates']);
    });

    it('leaves events without handlers, other repository reads and repeated wrapping alone', function (): void {
        $capture = recordEventSourcing(function (): void {
            event(new \Tests\Fixtures\EventSourcing\Unhandled());
            app(StoredEventRepository::class)->retrieveAll()->all();
        });

        expect($capture->rows('event_sourcing'))->toHaveCount(1)
            ->and($capture->rows('event_sourcing')[0]['handlers'])->toBe([])
            ->and($capture->rows('event_sourcing')[0]['duration'])->toBeNull()
            ->and($capture->rows('aggregates'))->toBe([])
            ->and($capture->spansOfKind('event_sourcing'))->toBe([])
            ->and(app(EventSourcingLens::class)->summarize(recordEventSourcing(fn () => null))->totals)->toBe([]);

        $projectionist = app(Projectionist::class);

        expect(TracedProjectionist::wrap($projectionist, app(EventSourcingLens::class), app(Eyepiece::class)))->toBe($projectionist);
    });

    it('hands queued handlers their events while nothing is recorded', function (): void {
        app(Eyepiece::class)->discard();
        $stored = app(StoredEventRepository::class)->persist(new \Tests\Fixtures\EventSourcing\MoneyAdded(3));

        app(Projectionist::class)->handle($stored);

        expect(QueuedProjector::$handled)->toBe(1);
    });

    it('passes methods of a repository of your own on', function (): void {
        $inner = new class () extends \Spatie\EventSourcing\StoredEvents\Repositories\EloquentStoredEventRepository {
            public function shout(string $word): string
            {
                return strtoupper($word);
            }
        };

        expect(new TracedStoredEventRepository($inner, app(EventSourcingLens::class), app(Eyepiece::class))->shout('hi'))->toBe('HI');
    });

    it('renders an event folded out with its handlers, exception and properties', function (): void {
        \Illuminate\Support\Facades\Gate::define('viewMonocle', fn (mixed $user = null): bool => true);
        \Illuminate\Support\Facades\Route::post('/open', fn () => event(new AccountOpened('broken')) ? 'ok' : 'ok');

        $this->post('/open')->assertOk();

        $id = latestCapture()->id;
        $html = $this->getJson("/_monocle/api/captures/{$id}/rows/event_sourcing/1/details")->assertOk()->json('html');

        expect($html)->toContain(AccountOpened::class, 'Handlers (1)', WelcomeReactor::class.'@handle', 'reactor', 'failed', 'welcome mail bounced', '&quot;name&quot;: &quot;broken&quot;');
    });
});
