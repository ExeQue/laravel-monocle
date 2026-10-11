<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Laravel;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Laravel\SanctumLens;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Fixtures\Models\User;

covers(SanctumLens::class);

describe(SanctumLens::class, function (): void {
    it('records the Sanctum token a request authenticated with', function (): void {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        (include dirname(__DIR__, 5).'/vendor/laravel/sanctum/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php')->up();
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

    it('ignores tokens authenticated while nothing is recording', function (): void {
        app(Eyepiece::class)->reset();

        event(new TokenAuthenticated(new PersonalAccessToken(['name' => 'cli'])));

        app(Eyepiece::class)->start(CaptureType::Request);

        expect(app(Eyepiece::class)->finalize()->rows('auth'))->toBe([]);
    });

    it('describes the token row it adds', function (): void {
        expect(app(SanctumLens::class)->description())->toContain('token id, name, abilities and owner');
    });
});
