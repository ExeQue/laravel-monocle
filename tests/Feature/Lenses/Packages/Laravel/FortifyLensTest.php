<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Laravel;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Laravel\FortifyLens;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Tests\Fixtures\Models\User;

covers(FortifyLens::class);

describe(FortifyLens::class, function (): void {
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
            ->and(app(FortifyLens::class)->summarize($failed)->totals['auth_2fa_failed'])->toBe(1)
            ->and($passed)->toContain('2fa_passed', 'login');
    });

    it('ignores Fortify events fired while nothing is recording', function (): void {
        app(Eyepiece::class)->reset();

        event(new TwoFactorAuthenticationFailed(new GenericUser(['id' => 1])));

        app(Eyepiece::class)->start(CaptureType::Request);

        expect(app(Eyepiece::class)->finalize()->rows('auth'))->toBe([]);
    });

    it('describes the rows it adds and promises never to record recovery codes', function (): void {
        expect(app(FortifyLens::class)->description())->toContain('2fa_failed', 'Recovery codes are never recorded');
    });
});
