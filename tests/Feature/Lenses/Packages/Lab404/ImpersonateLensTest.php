<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Lab404;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Auth\RecordsImpersonation;
use ExeQue\Monocle\Lenses\Packages\Lab404\ImpersonateLens;
use ExeQue\Monocle\Reader\SummaryBuilder;
use ExeQue\Monocle\Storage\Index;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\GenericUser;
use Lab404\Impersonate\Events\LeaveImpersonation;
use Lab404\Impersonate\Events\TakeImpersonation;

covers(ImpersonateLens::class, RecordsImpersonation::class);

describe(ImpersonateLens::class, function (): void {
    beforeEach(function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);
        $this->user = new GenericUser(['id' => 5, 'password' => 'x']);
    });

    it('records impersonation with lab404/laravel-impersonate', function (): void {
        $admin = new GenericUser(['id' => 1, 'password' => 'x']);

        event(new TakeImpersonation($admin, $this->user));
        event(new LeaveImpersonation($admin, $this->user));

        $rows = app(Eyepiece::class)->finalize()->rows('auth');

        expect($rows[0])->toMatchArray(['event' => 'impersonation_started', 'user' => GenericUser::class.'#5', 'impersonated_by' => GenericUser::class.'#1'])
            ->and($rows[1])->toMatchArray(['event' => 'impersonation_ended', 'user' => GenericUser::class.'#5', 'impersonated_by' => GenericUser::class.'#1']);
    });

    it('marks the authenticated user as impersonated while an impersonation lasts', function (): void {
        config(['laravel-impersonate.session_key' => 'impersonated_by', 'laravel-impersonate.session_guard' => 'impersonator_guard']);
        session()->put('impersonated_by', 1);
        session()->put('impersonator_guard', 'web');

        event(new Authenticated('web', $this->user));

        $capture = app(Eyepiece::class)->finalize();

        expect($capture->rows('auth')[0]['impersonated_by'])->toBe('#1 (web guard)')
            ->and(app(ImpersonateLens::class)->summarize($capture)->totals)->toBe(['auth_impersonated_by' => '#1 (web guard)'])
            ->and(app(SummaryBuilder::class)->build($capture, children: false)['groups']['auth_impersonated_by'])->toBe('auth')
            ->and(app(SummaryBuilder::class)->build($capture, children: false)['tags'])->toBe([['label' => 'impersonated by #1 (web guard)', 'tone' => 'warn']])
            ->and(Index::summarize($capture)['tags'])->toBe([['label' => 'impersonated by #1 (web guard)', 'tone' => 'warn']]);
    });

    it('does not mark the impersonator as impersonated when they are logged back in', function (): void {
        session()->put('impersonated_by', 5);
        session()->put('impersonator_guard', 'web');

        event(new Authenticated('web', $this->user));

        expect(app(Eyepiece::class)->finalize()->rows('auth')[0])->not->toHaveKey('impersonated_by');
    });

    it('leaves the impersonator out when nobody is impersonating', function (): void {
        event(new Authenticated('web', $this->user));

        expect(app(Eyepiece::class)->finalize()->rows('auth')[0])->not->toHaveKey('impersonated_by');
    });
});
