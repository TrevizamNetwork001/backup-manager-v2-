<?php

use App\Http\Controllers\IntegrationApiController;
use App\Http\Middleware\AuditIntegrationApi;
use App\Http\Middleware\AuthenticateIntegrationToken;
use App\Http\Middleware\EnsureIntegrationApiEnabled;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')
    ->middleware([EnsureIntegrationApiEnabled::class, AuditIntegrationApi::class, 'throttle:integration-api', AuthenticateIntegrationToken::class])
    ->group(function (): void {
        Route::get('health', [IntegrationApiController::class, 'health'])->name('health');
        Route::get('summary', [IntegrationApiController::class, 'summary'])->name('summary');
        Route::get('devices', [IntegrationApiController::class, 'devices'])->name('devices');
        Route::get('backups/recent', [IntegrationApiController::class, 'recentBackups'])->name('backups.recent');
        Route::get('failures', [IntegrationApiController::class, 'failures'])->name('failures');
    });
