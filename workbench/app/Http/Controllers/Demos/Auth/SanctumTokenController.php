<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Auth;

use Illuminate\View\View;
use Workbench\App\Models\User;

final class SanctumTokenController
{
    public function __invoke(): View
    {
        $token = User::query()->where('email', 'ada@example.com')->firstOrFail()->createToken('demo')->plainTextToken;

        return view('fetch', [
            'title' => 'Sanctum token',
            'description' => 'A token was created for Ada. Send it to an auth:sanctum route; that request is a capture of its own.',
            'method' => 'GET',
            'url' => '/api/me',
            'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token],
        ]);
    }
}
