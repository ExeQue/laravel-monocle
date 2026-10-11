<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Mirror;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Mirror\MirrorLens;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\GenericUser;
use Mirror\Events\ImpersonationStarted;
use Mirror\Events\ImpersonationStopped;

covers(MirrorLens::class);

describe(MirrorLens::class, function (): void {
    beforeEach(function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);
        $this->user = new GenericUser(['id' => 5, 'password' => 'x']);
        $this->admin = new GenericUser(['id' => 1, 'password' => 'x']);
    });

    it('records an impersonation starting and ending on the auth section', function (): void {
        event(new ImpersonationStarted($this->admin, $this->user, []));
        event(new ImpersonationStopped($this->admin, $this->user, []));

        $rows = app(Eyepiece::class)->finalize()->rows('auth');

        expect($rows[0])->toMatchArray(['event' => 'impersonation_started', 'user' => GenericUser::class.'#5', 'impersonated_by' => GenericUser::class.'#1'])
            ->and($rows[1])->toMatchArray(['event' => 'impersonation_ended', 'user' => GenericUser::class.'#5', 'impersonated_by' => GenericUser::class.'#1']);
    });

    it('marks the authenticated user as impersonated while an impersonation lasts', function (): void {
        session()->put('mirror.impersonation.payload', ['impersonator_id' => 1, 'impersonator_guard' => 'web', 'impersonated_id' => 5]);

        event(new Authenticated('web', $this->user));

        $capture = app(Eyepiece::class)->finalize();

        expect($capture->rows('auth')[0]['impersonated_by'])->toBe('#1 (web guard)')
            ->and(app(MirrorLens::class)->summarize($capture)->totals)->toBe(['auth_impersonated_by' => '#1 (web guard)']);
    });
});
