<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\AuthLens;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;

covers(AuthLens::class);

describe(AuthLens::class, function (): void {
    beforeEach(function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);
        $this->user = new GenericUser(['id' => 5, 'password' => 'x']);
    });

    it('records how the request was authenticated without credential values', function (): void {
        event(new Attempting('web', ['email' => 'a@b.c', 'password' => 'hunter2'], true));
        event(new Failed('web', null, ['email' => 'a@b.c', 'password' => 'hunter2']));
        event(new Login('web', $this->user, false));
        event(new Authenticated('web', $this->user));

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('auth');
        $summary = app(AuthLens::class)->summarize($capture);

        expect(array_column($rows, 'event'))->toBe(['attempting', 'failed', 'login', 'authenticated'])
            ->and($rows[0]['credentials'])->toBe(['email', 'password'])
            ->and(json_encode($rows))->not->toContain('hunter2')
            ->and($rows[2]['user'])->toBe(GenericUser::class.'#5')
            ->and($rows[3]['guard'])->toBe('web')
            ->and($rows[3])->not->toHaveKey('origin')
            ->and($rows[2])->toHaveKey('origin')
            ->and($summary->totals)->toBe(['auth_user' => GenericUser::class.'#5', 'auth_failed' => 1])
            ->and($summary->warnings)->toBe([]);
    });

    it('warns about lockouts', function (): void {
        event(new Lockout(Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1'])));

        $capture = app(Eyepiece::class)->finalize();

        expect($capture->rows('auth')[0])->toMatchArray(['event' => 'lockout', 'ip' => '10.0.0.1'])
            ->and(app(AuthLens::class)->summarize($capture)->warnings)->toBe(['A login was locked out after too many attempts.']);
    });

    it('summarizes nothing when there was no authentication', function (): void {
        expect(app(AuthLens::class)->summarize(app(Eyepiece::class)->finalize()))->toEqual(\ExeQue\Monocle\Data\Summary::make());
    });
    it('describes itself for the agent and the dashboard', function (): void {
        $lens = app(AuthLens::class);

        expect($lens->description())->toContain('credential keys tried')
            ->and($lens->advice())->toEndWith('lockouts.')
            ->and($lens->presentation()->tabView)->toBe('monocle::tabs.auth');
    });

    it('records nothing while no capture is recording', function (): void {
        app(Eyepiece::class)->discard();

        event(new Login('web', $this->user, false));

        expect(app(Eyepiece::class)->active()->rows('auth'))->toBe([]);
    });
});
