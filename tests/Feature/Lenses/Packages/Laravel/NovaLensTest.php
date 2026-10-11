<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Laravel;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Laravel\NovaLens;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\GenericUser;
use Laravel\Nova\Events\StartedImpersonating;
use Laravel\Nova\Events\StoppedImpersonating;

covers(NovaLens::class);

describe(NovaLens::class, function (): void {
    beforeEach(function (): void {
        require_once dirname(__DIR__, 4).'/Fixtures/Nova/events.php';
        // Nova is not installed, so the lens is not booted on its own.
        app(NovaLens::class)->boot();
        app(Eyepiece::class)->start(CaptureType::Request);
        $this->user = new GenericUser(['id' => 5, 'password' => 'x']);
        $this->admin = new GenericUser(['id' => 1, 'password' => 'x']);
    });

    it('records an impersonation starting and ending on the auth section', function (): void {
        event(new StartedImpersonating($this->admin, $this->user));
        event(new StoppedImpersonating($this->admin, $this->user));

        $rows = app(Eyepiece::class)->finalize()->rows('auth');

        expect($rows[0])->toMatchArray(['event' => 'impersonation_started', 'user' => GenericUser::class.'#5', 'impersonated_by' => GenericUser::class.'#1'])
            ->and($rows[1])->toMatchArray(['event' => 'impersonation_ended', 'user' => GenericUser::class.'#5', 'impersonated_by' => GenericUser::class.'#1']);
    });

    it('marks the authenticated user as impersonated while an impersonation lasts', function (): void {
        session()->put('nova_impersonated_by', 1);
        session()->put('nova_impersonated_guard', 'web');

        event(new Authenticated('web', $this->user));

        $capture = app(Eyepiece::class)->finalize();

        expect($capture->rows('auth')[0]['impersonated_by'])->toBe('#1 (web guard)')
            ->and(app(NovaLens::class)->summarize($capture)->totals)->toBe(['auth_impersonated_by' => '#1 (web guard)']);
    });
});
