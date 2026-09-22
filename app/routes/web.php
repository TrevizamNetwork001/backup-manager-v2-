<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupExecutionController;
use App\Http\Controllers\BackupArtifactController;
use App\Http\Controllers\BackupPolicyController;
use App\Http\Controllers\DeviceBackupPolicyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::resource('sites', SiteController::class)
        ->except(['show']);

    Route::resource('devices', DeviceController::class)
        ->except(['show']);

    Route::resource('credentials', CredentialController::class)
        ->except(['show']);

    Route::resource('backup-policies', BackupPolicyController::class)
        ->except(['show']);
    Route::post('backup-policies/{backup_policy}/associations', [DeviceBackupPolicyController::class, 'store'])->name('backup-policies.associations.store');
    Route::patch('backup-policies/{backup_policy}/associations/{association}', [DeviceBackupPolicyController::class, 'update'])->name('backup-policies.associations.update');
    Route::delete('backup-policies/{backup_policy}/associations/{association}', [DeviceBackupPolicyController::class, 'destroy'])->name('backup-policies.associations.destroy');
    Route::post('backup-policies/{backup_policy}/associations/{association}/executions', [BackupExecutionController::class, 'storeManual'])->name('backup-policies.associations.executions.store');

    Route::get('backup-executions', [BackupExecutionController::class, 'index'])->name('backup-executions.index');
    Route::get('backup-executions/{backup_execution}', [BackupExecutionController::class, 'show'])->name('backup-executions.show');
    Route::post('backup-executions/{backup_execution}/queue', [BackupExecutionController::class, 'queue'])->name('backup-executions.queue');
    Route::post('backup-executions/{backup_execution}/cancel', [BackupExecutionController::class, 'cancel'])->name('backup-executions.cancel');
    Route::get('backup-artifacts', [BackupArtifactController::class, 'index'])->name('backup-artifacts.index');
    Route::get('backup-artifacts/{backup_artifact}', [BackupArtifactController::class, 'show'])->name('backup-artifacts.show');

    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
