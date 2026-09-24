<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupExecutionController;
use App\Http\Controllers\BackupArtifactController;
use App\Http\Controllers\BackupPolicyController;
use App\Http\Controllers\DeviceBackupPolicyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\FtpAccountController;
use App\Http\Controllers\FtpAdminController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\InstanceSettingsController;
use App\Http\Controllers\OltFtpWizardController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('ftp', [FtpAdminController::class, 'index'])->name('ftp.index');
    Route::post('ftp/accounts', [FtpAdminController::class, 'store'])->name('ftp.store');
    Route::get('ftp/accounts/{ftpAccount}', [FtpAdminController::class, 'show'])->name('ftp.show');
    Route::post('ftp/accounts/{ftpAccount}/prepare', [FtpAdminController::class, 'prepare'])->name('ftp.prepare');
    Route::post('ftp/accounts/{ftpAccount}/rotate', [FtpAdminController::class, 'rotate'])->name('ftp.rotate');
    Route::patch('ftp/accounts/{ftpAccount}/status', [FtpAdminController::class, 'status'])->name('ftp.status');
    Route::delete('ftp/accounts/{ftpAccount}', [FtpAdminController::class, 'delete'])->name('ftp.delete');
    Route::get('settings', [InstanceSettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [InstanceSettingsController::class, 'update'])->name('settings.update');

    Route::resource('sites', SiteController::class)
        ->except(['show']);

    Route::resource('devices', DeviceController::class)
        ->except(['show']);
    Route::post('devices/{device}/ftp-account', [FtpAccountController::class, 'store'])->name('devices.ftp-account.store');
    Route::patch('devices/{device}/ftp-account', [FtpAccountController::class, 'update'])->name('devices.ftp-account.update');
    Route::post('devices/{device}/ftp-account/rotate', [FtpAccountController::class, 'rotate'])->name('devices.ftp-account.rotate');
    Route::post('devices/{device}/ftp-account/replace', [FtpAccountController::class, 'replace'])->name('devices.ftp-account.replace');
    Route::post('devices/{device}/ftp-account/retry', [FtpAccountController::class, 'retry'])->name('devices.ftp-account.retry');
    Route::get('devices/{device}/olt-ftp/status', [OltFtpWizardController::class, 'status'])->name('devices.olt-ftp.status');
    Route::post('devices/{device}/olt-ftp/server', [OltFtpWizardController::class, 'saveServer'])->name('devices.olt-ftp.server');
    Route::post('devices/{device}/olt-ftp/confirm', [OltFtpWizardController::class, 'confirm'])->name('devices.olt-ftp.confirm');
    Route::post('devices/{device}/olt-ftp/test', [OltFtpWizardController::class, 'test'])->name('devices.olt-ftp.test');
    Route::post('devices/{device}/ssh-host-key/trust', [DeviceController::class, 'trustHostKey'])
        ->name('devices.ssh-host-key.trust');

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
