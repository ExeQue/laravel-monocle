<?php

declare(strict_types=1);

use ExeQue\Monocle\Http\Controllers\AssetController;
use ExeQue\Monocle\Http\Controllers\CapturesController;
use ExeQue\Monocle\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('assets/{file}', AssetController::class)->name('asset');

Route::prefix('api')->name('api.')->group(function (): void {
    Route::get('captures', [CapturesController::class, 'index'])->name('captures');
    Route::delete('captures', [CapturesController::class, 'destroy'])->name('captures.clear');
    Route::get('captures/{id}', [CapturesController::class, 'show'])->name('captures.show');
    Route::get('captures/{id}/rows/{section}', [CapturesController::class, 'rows'])->name('captures.rows');
    Route::get('captures/{id}/rows/{section}/{n}/details', [CapturesController::class, 'details'])->whereNumber('n')->name('captures.details');
    Route::get('captures/{id}/queries', [CapturesController::class, 'queries'])->name('captures.queries');
    Route::get('captures/{id}/trace', [CapturesController::class, 'trace'])->name('captures.trace');
});

Route::get('/', DashboardController::class)->name('dashboard');
