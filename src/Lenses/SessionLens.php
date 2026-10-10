<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Throwable;

/**
 * What the session held once the request was handled. Values under keys that
 * match the input masking pattern (the CSRF token among them) are masked.
 */
final class SessionLens implements Lens
{
    private Eyepiece $eyepiece;

    public function key(): string
    {
        return 'session';
    }

    public function description(): string
    {
        return 'Session contents after the request was handled: data (masked like input), flash (keys flashed for the next request), the driver and a short hash of the session id.';
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        $this->eyepiece = $eyepiece;

        $eyepiece->listen(RequestHandled::class, function (RequestHandled $event) use ($app): void {
            if (! $this->eyepiece->recording() || ! $event->request->hasSession()) {
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

            $this->eyepiece->set('session', [
                'driver' => $app->make('config')->get('session.driver'),
                'id' => substr(hash('sha256', (string) $session->getId()), 0, 12),
                'data' => $this->eyepiece->masker()->inputs($data),
                'flash' => array_values(array_unique([...(array) ($flash['old'] ?? []), ...(array) ($flash['new'] ?? [])])),
            ]);
        });
    }
}
