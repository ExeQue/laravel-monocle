<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Workbench\App\Mcp\DemoServer;

Route::middleware('auth:sanctum')->get('/api/me', fn (Request $request): array => [
    'id' => $request->user()->id,
    'email' => $request->user()->email,
]);

Mcp::web('/api/mcp/demo', DemoServer::class);
