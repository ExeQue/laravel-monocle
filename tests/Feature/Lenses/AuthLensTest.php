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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Models\User;

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
            ->and($summary->totals())->toBe(['auth_user' => GenericUser::class.'#5', 'auth_failed' => 1])
            ->and($summary->warnings())->toBe([]);
    });

    it('records the Sanctum token a request authenticated with', function (): void {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        (include dirname(__DIR__, 3).'/vendor/laravel/sanctum/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php')->up();
        config(['auth.providers.users.model' => User::class]);

        $user = User::query()->create(['name' => 'Ada']);
        $token = $user->createToken('cli', ['orders:read']);

        Route::middleware('auth:sanctum')->get('/api/orders', fn () => 'orders');

        $this->withToken($token->plainTextToken)->get('/api/orders')->assertOk();

        $row = collect(latestCapture()->rows('auth'))->firstWhere('event', 'token');

        expect($row)->toMatchArray([
            'guard' => 'sanctum',
            'token_id' => $token->accessToken->getKey(),
            'token_name' => 'cli',
            'abilities' => ['orders:read'],
            'owner' => User::class.'#'.$user->id,
        ]);
    });

    it('records Fortify two-factor challenges, failed codes and passed codes', function (): void {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        config(['auth.providers.users.model' => User::class]);

        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $secret = $google2fa->generateSecretKey();
        User::query()->create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('secret-password'),
            'two_factor_secret' => encrypt($secret),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->postJson('/login', ['email' => 'ada@example.com', 'password' => 'secret-password'])->assertOk()->assertJson(['two_factor' => true]);
        $challenged = collect(latestCapture()->rows('auth'))->pluck('event');

        $this->postJson('/two-factor-challenge', ['code' => '000000'])->assertStatus(422);
        $failed = latestCapture();

        $this->postJson('/two-factor-challenge', ['code' => $google2fa->getCurrentOtp($secret)])->assertNoContent();
        $passed = collect(latestCapture()->rows('auth'))->pluck('event');

        expect($challenged)->toContain('2fa_challenged')
            ->and(collect($failed->rows('auth'))->pluck('event'))->toContain('2fa_failed')
            ->and(app(AuthLens::class)->summarize($failed)->totals()['auth_2fa_failed'])->toBe(1)
            ->and($passed)->toContain('2fa_passed', 'login');
    });

    it('warns about lockouts', function (): void {
        event(new Lockout(Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1'])));

        $capture = app(Eyepiece::class)->finalize();

        expect($capture->rows('auth')[0])->toMatchArray(['event' => 'lockout', 'ip' => '10.0.0.1'])
            ->and(app(AuthLens::class)->summarize($capture)->warnings())->toBe(['A login was locked out after too many attempts.']);
    });

    it('summarizes nothing when there was no authentication', function (): void {
        expect(app(AuthLens::class)->summarize(app(Eyepiece::class)->finalize()))->toEqual(new \ExeQue\Monocle\Data\Summary());
    });
});
