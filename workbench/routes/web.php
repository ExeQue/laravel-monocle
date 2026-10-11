<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Spatie\ResponseCache\Middlewares\CacheResponse;
use Workbench\App\Http\Controllers\Demos\Ai\{AgentCallController, FailedAgentController, FailedToolController as AiFailedToolController};
use Workbench\App\Http\Controllers\Demos\Auth\{FailedAttemptController, LockoutController, LoginController, LogoutController, SanctumTokenController};
use Workbench\App\Http\Controllers\Demos\Auth\Impersonation\{StartImpersonatingController, StopImpersonatingController, WhoAmIController};
use Workbench\App\Http\Controllers\Demos\Authorization\{AllowedGateController, DeniedGateController};
use Workbench\App\Http\Controllers\Demos\Cache\{HitsAndMissesController, SlowCacheStoreController};
use Workbench\App\Http\Controllers\Demos\Commands\{ArtisanCallController, FailedCommandController, FailedScheduledTaskController};
use Workbench\App\Http\Controllers\Demos\Database\{DuplicateQueryController, NPlusOneController, RolledBackTransactionController, SlowQueryController};
use Workbench\App\Http\Controllers\Demos\Dumps\{DieAndDumpController, DumpController};
use Workbench\App\Http\Controllers\Demos\Eloquent\ModelChangesController;
use Workbench\App\Http\Controllers\Demos\Events\CustomEventController;
use Workbench\App\Http\Controllers\Demos\Exceptions\{ReportedExceptionController, ServerErrorResponseController, ThrownExceptionController};
use Workbench\App\Http\Controllers\Demos\Filesystem\{FailedReadController, PutGetDeleteController};
use Workbench\App\Http\Controllers\Demos\HttpClient\{FailedConnectionsController, FakedApiController, UnknownHostController};
use Workbench\App\Http\Controllers\Demos\Inertia\{InertiaPageController, LargePageController, SharedPropOnPartialController};
use Workbench\App\Http\Controllers\Demos\Livewire\{ComponentExceptionController, CounterController};
use Workbench\App\Http\Controllers\Demos\Log\LogEntriesController;
use Workbench\App\Http\Controllers\Demos\Mail\MailableAndNotificationController;
use Workbench\App\Http\Controllers\Demos\Mcp\{ErrorResponseController, ToolCallController};
use Workbench\App\Http\Controllers\Demos\MediaLibrary\AddMediaController;
use Workbench\App\Http\Controllers\Demos\Middleware\{ShortCircuitController, SlowMiddlewareController};
use Workbench\App\Http\Controllers\Demos\Monocle\{MonocleFailureController, OpenSpanController};
use Workbench\App\Http\Controllers\Demos\Pennant\FeatureFlagsController;
use Workbench\App\Http\Controllers\Demos\Prism\{FailedCallController, FailedToolController as PrismFailedToolController, TextCallController};
use Workbench\App\Http\Controllers\Demos\Processes\{ProcessTimeoutController, RunProcessesController, SlowProcessController};
use Workbench\App\Http\Controllers\Demos\Queue\{CountdownController, DispatchJobController, FailedQueuedJobController, FailingJobController, JobDispatchingJobController, QueueJobController, WorkQueueController};
use Workbench\App\Http\Controllers\Demos\ResponseCache\{CachedResponseController, ClearCacheController};
use Workbench\App\Http\Controllers\Demos\Session\WriteAndFlashController;
use Workbench\App\Http\Controllers\Demos\ShowcaseController;
use Workbench\App\Http\Controllers\Demos\Spans\CustomSpansController;
use Workbench\App\Http\Controllers\Demos\Validation\{FormRequestController, InlineValidationController};
use Workbench\App\Http\Controllers\Demos\Views\{ComponentsAndPartialsController, RepeatedViewController};
use Workbench\App\Http\Middleware\ShortCircuitMiddleware;
use Workbench\App\Http\Middleware\SlowMiddleware;

Route::view('/', 'index')->name('home');

Route::prefix('demo')->name('demo.')->group(function (): void {
    // Every lens in one request.
    Route::get('showcase', ShowcaseController::class)->middleware(SlowMiddleware::class)->name('showcase');

    // Database
    Route::get('database/n-plus-one', NPlusOneController::class)->name('database.n-plus-one');
    Route::get('database/slow', SlowQueryController::class)->name('database.slow');
    Route::get('database/duplicate', DuplicateQueryController::class)->name('database.duplicate');
    Route::get('database/rollback', RolledBackTransactionController::class)->name('database.rollback');

    // Eloquent
    Route::get('eloquent', ModelChangesController::class)->name('eloquent');

    // Cache
    Route::get('cache', HitsAndMissesController::class)->name('cache');

    // Views
    Route::get('views', ComponentsAndPartialsController::class)->name('views');

    // Validation
    Route::view('validation', 'validation')->name('validation');
    Route::post('validation/form-request', FormRequestController::class)->name('validation.form-request');
    Route::get('validation/inline', InlineValidationController::class)->name('validation.inline');

    // Session
    Route::get('session', WriteAndFlashController::class)->name('session');

    // Auth, including impersonation
    Route::post('auth/login', LoginController::class)->name('auth.login');
    Route::post('auth/failed', FailedAttemptController::class)->name('auth.failed');
    Route::post('auth/logout', LogoutController::class)->name('auth.logout');
    Route::get('impersonate/start', StartImpersonatingController::class)->name('impersonate.start');
    Route::get('impersonate/whoami', WhoAmIController::class)->name('impersonate.whoami');
    Route::get('impersonate/stop', StopImpersonatingController::class)->name('impersonate.stop');
    Route::get('auth/token', SanctumTokenController::class)->name('auth.token');

    // Authorization
    Route::get('authorization/allowed', AllowedGateController::class)->name('authorization.allowed');
    Route::get('authorization/denied', DeniedGateController::class)->name('authorization.denied');

    // Events
    Route::get('events', CustomEventController::class)->name('events');

    // Queue
    Route::get('queue', DispatchJobController::class)->name('queue');
    Route::get('queue/database', QueueJobController::class)->name('queue.database');
    Route::get('queue/countdown', CountdownController::class)->name('queue.countdown');
    Route::get('queue/work', WorkQueueController::class)->name('queue.work');
    Route::get('queue/chain', JobDispatchingJobController::class)->name('queue.chain');
    Route::get('queue/failing', FailingJobController::class)->name('queue.failing');

    // Processes
    Route::get('process', RunProcessesController::class)->name('process');

    // Mail and notifications
    Route::get('mail', MailableAndNotificationController::class)->name('mail');

    // HTTP client
    Route::get('http', FakedApiController::class)->name('http');
    Route::get('http/failures', FailedConnectionsController::class)->name('http.failures');

    // Filesystem
    Route::get('filesystem', PutGetDeleteController::class)->name('filesystem');

    // Log
    Route::get('log', LogEntriesController::class)->name('log');

    // Exceptions
    Route::get('exceptions/reported', ReportedExceptionController::class)->name('exceptions.reported');
    Route::get('exceptions/thrown', ThrownExceptionController::class)->name('exceptions.thrown');

    // Dumps
    Route::get('dump', DumpController::class)->name('dump');

    // Middleware
    Route::get('middleware', SlowMiddlewareController::class)->middleware(SlowMiddleware::class)->name('middleware');

    // Artisan
    Route::get('artisan', ArtisanCallController::class)->name('artisan');

    // Livewire
    Route::get('livewire', CounterController::class)->name('livewire');

    // Inertia
    Route::get('inertia', InertiaPageController::class)->name('inertia');

    // Pennant
    Route::get('pennant', FeatureFlagsController::class)->name('pennant');

    // Response cache
    Route::get('response-cache', CachedResponseController::class)->middleware(CacheResponse::class)->name('response-cache');
    Route::get('response-cache/clear', ClearCacheController::class)->name('response-cache.clear');

    // Media library
    Route::get('media', AddMediaController::class)->name('media');

    // AI
    Route::get('ai', AgentCallController::class)->name('ai');

    // Prism
    Route::get('prism', TextCallController::class)->name('prism');

    // MCP
    Route::get('mcp', ToolCallController::class)->name('mcp');

    // Custom spans
    Route::get('spans', CustomSpansController::class)->name('spans');

    // One route per warning or error Monocle can raise; the demo index lists them by lens.
    Route::prefix('alarms')->name('alarms.')->group(function (): void {
        // Cache
        Route::get('slow-cache', SlowCacheStoreController::class)->name('slow-cache');

        // Views
        Route::get('repeated-view', RepeatedViewController::class)->name('repeated-view');

        // Middleware
        Route::get('middleware-short-circuit', ShortCircuitController::class)->middleware(ShortCircuitMiddleware::class)->name('middleware-short-circuit');

        // Dumps
        Route::get('dd', DieAndDumpController::class)->name('dd');

        // Auth
        Route::get('lockout', LockoutController::class)->name('lockout');

        // Exceptions
        Route::get('server-error', ServerErrorResponseController::class)->name('server-error');

        // Queue
        Route::get('failed-job', FailedQueuedJobController::class)->name('failed-job');

        // Artisan
        Route::get('failed-command', FailedCommandController::class)->name('failed-command');
        Route::get('failed-scheduled-task', FailedScheduledTaskController::class)->name('failed-scheduled-task');

        // Processes
        Route::get('slow-process', SlowProcessController::class)->name('slow-process');
        Route::get('process-timeout', ProcessTimeoutController::class)->name('process-timeout');

        // Filesystem
        Route::get('filesystem-failed', FailedReadController::class)->name('filesystem-failed');

        // HTTP client
        Route::get('http-connection-failed', UnknownHostController::class)->name('http-connection-failed');

        // Inertia
        Route::get('inertia-large-page', LargePageController::class)->name('inertia-large-page');
        Route::get('inertia-shared-on-partial', SharedPropOnPartialController::class)->name('inertia-shared-on-partial');

        // Livewire
        Route::get('livewire-exception', ComponentExceptionController::class)->name('livewire-exception');

        // AI
        Route::get('ai-agent-failed', FailedAgentController::class)->name('ai-agent-failed');
        Route::get('ai-tool-failed', AiFailedToolController::class)->name('ai-tool-failed');

        // Prism
        Route::get('prism-failed', FailedCallController::class)->name('prism-failed');
        Route::get('prism-tool-failed', PrismFailedToolController::class)->name('prism-tool-failed');

        // MCP
        Route::get('mcp-error', ErrorResponseController::class)->name('mcp-error');

        // Monocle itself
        Route::get('monocle-failure', MonocleFailureController::class)->name('monocle-failure');
        Route::get('open-span', OpenSpanController::class)->name('open-span');
    });
});
