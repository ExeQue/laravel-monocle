<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Session\SnapshotsSession;
use ExeQue\Monocle\Support\Normalizer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Arr;
use Throwable;

/**
 * What the session held once the request was handled, and what the request
 * changed in it. Values under keys that match the input masking pattern (the
 * CSRF token among them) are masked.
 */
final class SessionLens implements Advises, Lens, Presentable, Resettable
{
    /** @var array<string, mixed>|null The session right after it started. */
    private ?array $before = null;

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'session';
    }

    public function description(): string
    {
        return 'Session contents after the request was handled: data (masked like input), changes (one {field, from, to} per dot-notated key the request added, changed or removed; from is null for new keys, to is null for removed ones), flash (keys flashed for the next request), the driver and a short hash of the session id.';
    }

    public function advice(): string
    {
        return 'Look here when behaviour depends on session state, such as flash messages, intended URLs or wizard steps, and to see what the request changed in it.';
    }

    public function boot(Application $app): void
    {
        $remember = function (array $data): void {
            $this->before = $data;
        };

        $app->extend(StartSession::class, fn (object $inner): SnapshotsSession => new SnapshotsSession(
            // Unwrap first, so the session is never snapshotted twice.
            $inner instanceof SnapshotsSession ? $inner->inner() : $inner,
            $remember,
        ));

        $this->eyepiece->listen(RequestHandled::class, function (RequestHandled $event) use ($app): void {
            if (! $this->eyepiece->recording()) {
                return;
            }

            if (! $event->request->hasSession()) {
                return;
            }

            try {
                $session = $event->request->session();
                $data = $session->all();
            } catch (Throwable) {
                return;
            }

            $flash = (array) ($data['_flash'] ?? []);
            unset($data['_flash']);

            $masker = $this->eyepiece->masker(self::class);
            $after = $masker->inputs($data);

            $changes = null;

            if ($this->before !== null) {
                $before = $masker->inputs(Arr::except($this->before, ['_flash']));
                $changes = $this->changes($before, $after);
            }

            $flashed = [...(array) ($flash['old'] ?? []), ...(array) ($flash['new'] ?? [])];

            // Only a hash of the id: the id itself would let anyone reading the capture take over the session.
            $idHash = substr(hash('sha256', (string) $session->getId()), 0, 12);

            $this->eyepiece->set('session', [
                'driver' => $app->make('config')->get('session.driver'),
                'id' => $idHash,
                'data' => $after,
                'changes' => $changes,
                'flash' => array_values(array_unique($flashed)),
            ]);
        });
    }

    public function reset(): void
    {
        $this->before = null;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{field: string, from: mixed, to: mixed}>
     */
    private function changes(array $before, array $after): array
    {
        $from = Arr::dot(Normalizer::normalize($before));
        $to = Arr::dot(Normalizer::normalize($after));
        $changes = [];

        foreach (array_unique([...array_keys($from), ...array_keys($to)]) as $field) {
            $old = $from[$field] ?? null;
            $new = $to[$field] ?? null;

            // A key added or removed with a null value is a change too, though both sides read null.
            $existenceChanged = array_key_exists($field, $from) !== array_key_exists($field, $to);

            if ($old !== $new || $existenceChanged) {
                $changes[] = ['field' => (string) $field, 'from' => $old, 'to' => $new];
            }
        }

        return $changes;
    }

    public function presentation(): Presentation
    {
        return Presentation::table()->tab('monocle::tabs.session');
    }
}
