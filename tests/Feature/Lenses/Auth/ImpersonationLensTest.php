<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Auth;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Auth\ImpersonationLens;
use ExeQue\Monocle\Lenses\Packages\Lab404\ImpersonateLens;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\GenericUser;
use Lab404\Impersonate\Events\TakeImpersonation;

covers(ImpersonationLens::class);

describe(ImpersonationLens::class, function (): void {
    beforeEach(function (): void {
        $this->admin = new GenericUser(['id' => 1, 'password' => 'x']);
        $this->user = new GenericUser(['id' => 5, 'password' => 'x']);
    });

    it('describes itself with the package it records', function (): void {
        expect(app(ImpersonateLens::class)->description())->toStartWith('With lab404/laravel-impersonate: impersonation_started and impersonation_ended rows');
    });

    it('records nothing about an impersonation outside a capture', function (): void {
        event(new TakeImpersonation($this->admin, $this->user));

        app(Eyepiece::class)->start(CaptureType::Request);

        expect(app(Eyepiece::class)->finalize()->rows('auth'))->toBe([]);
    });

    it('leaves out a user that is not an authenticatable', function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);

        // The impersonator can be gone by the time a serialized event is handled.
        $event = new TakeImpersonation($this->admin, $this->user);
        $event->impersonator = null;
        event($event);

        expect(app(Eyepiece::class)->finalize()->rows('auth')[0])->toMatchArray([
            'event' => 'impersonation_started',
            'user' => GenericUser::class.'#5',
            'impersonated_by' => null,
        ]);
    });

    it('leaves the authenticated row alone when there is no session to read', function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);

        config(['session.driver' => 'missing-driver']);
        app('session')->forgetDrivers();

        event(new Authenticated('api', $this->user));

        expect(app(Eyepiece::class)->finalize()->rows('auth')[0])->toMatchArray(['event' => 'authenticated'])
            ->not->toHaveKey('impersonated_by');
    });
});
