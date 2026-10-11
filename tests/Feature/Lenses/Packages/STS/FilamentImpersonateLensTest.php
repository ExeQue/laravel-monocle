<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\STS;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\STS\FilamentImpersonateLens;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\GenericUser;
use STS\FilamentImpersonate\Events\EnterImpersonation;
use STS\FilamentImpersonate\Events\LeaveImpersonation;

covers(FilamentImpersonateLens::class);

describe(FilamentImpersonateLens::class, function (): void {
    beforeEach(function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);
        $this->user = new GenericUser(['id' => 5, 'password' => 'x']);
        $this->admin = new GenericUser(['id' => 1, 'password' => 'x']);
    });

    it('records an impersonation starting and ending on the auth section', function (): void {
        event(new EnterImpersonation($this->admin, $this->user));
        event(new LeaveImpersonation($this->admin, $this->user));

        $rows = app(Eyepiece::class)->finalize()->rows('auth');

        expect($rows[0])->toMatchArray(['event' => 'impersonation_started', 'user' => GenericUser::class.'#5', 'impersonated_by' => GenericUser::class.'#1'])
            ->and($rows[1])->toMatchArray(['event' => 'impersonation_ended', 'user' => GenericUser::class.'#5', 'impersonated_by' => GenericUser::class.'#1']);
    });

    it('marks the authenticated user as impersonated while an impersonation lasts', function (): void {
        session()->put('impersonated_by', 1);
        session()->put('impersonator_guard', 'web');

        event(new Authenticated('web', $this->user));

        $capture = app(Eyepiece::class)->finalize();

        expect($capture->rows('auth')[0]['impersonated_by'])->toBe('#1 (web guard)')
            ->and(app(FilamentImpersonateLens::class)->summarize($capture)->totals)->toBe(['auth_impersonated_by' => '#1 (web guard)']);
    });
});
