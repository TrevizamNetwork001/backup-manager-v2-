<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupArtifactController;
use App\Http\Controllers\BackupExecutionController;
use App\Http\Controllers\BackupPolicyController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceBackupHealthController;
use App\Http\Controllers\DeviceBackupPolicyController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DeviceDocumentationController;
use App\Http\Controllers\FtpAccountController;
use App\Http\Controllers\FtpAdminController;
use App\Http\Controllers\InstanceSettingsController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\OltFtpWizardController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SessionKeepAliveController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('session/keep-alive', SessionKeepAliveController::class)->name('session.keep-alive');
    Route::get('backup-health', [DeviceBackupHealthController::class, 'index'])->name('backup-health.index');
    Route::get('ftp', [FtpAdminController::class, 'index'])->name('ftp.index');
    Route::post('ftp/accounts', [FtpAdminController::class, 'store'])->name('ftp.store');
    Route::get('ftp/accounts/{ftpAccount}', [FtpAdminController::class, 'show'])->name('ftp.show');
    Route::post('ftp/accounts/{ftpAccount}/prepare', [FtpAdminController::class, 'prepare'])->name('ftp.prepare');
    Route::post('ftp/accounts/{ftpAccount}/rotate', [FtpAdminController::class, 'rotate'])->name('ftp.rotate');
    Route::post('ftp/accounts/{ftpAccount}/secret/reveal', [FtpAdminController::class, 'revealSecret'])
        ->middleware('throttle:6,1')->name('ftp.secret.reveal');
    Route::patch('ftp/accounts/{ftpAccount}/status', [FtpAdminController::class, 'status'])->name('ftp.status');
    Route::delete('ftp/accounts/{ftpAccount}', [FtpAdminController::class, 'delete'])->name('ftp.delete');
    Route::get('settings', [InstanceSettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [InstanceSettingsController::class, 'update'])->name('settings.update');
    Route::put('settings/ftp-access', [InstanceSettingsController::class, 'updateFtpAccess'])->name('settings.ftp-access.update');
    Route::post('settings/retention/preview', [InstanceSettingsController::class, 'previewRetention'])->name('settings.retention.preview');
    Route::patch('settings/retention', [InstanceSettingsController::class, 'updateRetention'])->name('settings.retention.update');
    Route::get('settings/notifications', [NotificationSettingsController::class, 'edit'])->name('settings.notifications.edit');
    Route::put('settings/notifications', [NotificationSettingsController::class, 'update'])->name('settings.notifications.update');
    Route::post('settings/notifications/token/reveal', [NotificationSettingsController::class, 'revealToken'])->middleware('throttle:6,1')->name('settings.notifications.token.reveal');
    Route::post('settings/notifications/summary-test/{kind}', [NotificationSettingsController::class, 'testSummary'])->middleware('throttle:6,1')->whereIn('kind', ['daily', 'weekly'])->name('settings.notifications.summary-test');
    Route::put('settings/notifications/backup-copy', [NotificationSettingsController::class, 'updateBackupCopy'])->name('settings.notifications.backup-copy.update');
    Route::post('settings/notifications/backup-copy/test', [NotificationSettingsController::class, 'testBackupCopy'])->middleware('throttle:6,1')->name('settings.notifications.backup-copy.test');
    Route::post('settings/notifications/test', [NotificationSettingsController::class, 'test'])->middleware('throttle:6,1')->name('settings.notifications.test');
    Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
    Route::get('system/health', [SystemHealthController::class, 'index'])->name('system-health.index');

    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('executions', [ReportController::class, 'executions'])->name('executions');
        Route::get('executions/export', [ReportController::class, 'executionsExport'])->name('executions.export');
        Route::get('devices', [ReportController::class, 'devices'])->name('devices');
        Route::get('devices/export', [ReportController::class, 'devicesExport'])->name('devices.export');
        Route::get('documentation', [DeviceDocumentationController::class, 'index'])->name('documentation');
        Route::get('documentation/export.csv', [DeviceDocumentationController::class, 'csv'])->name('documentation.csv');
        Route::get('documentation/export.pdf', [DeviceDocumentationController::class, 'pdf'])->name('documentation.pdf');
        Route::get('artifacts', [ReportController::class, 'artifacts'])->name('artifacts');
        Route::get('artifacts/export', [ReportController::class, 'artifactsExport'])->name('artifacts.export');
        Route::get('failures', [ReportController::class, 'failures'])->name('failures');
        Route::get('failures/export', [ReportController::class, 'failuresExport'])->name('failures.export');
        Route::get('ftp', [ReportController::class, 'ftp'])->name('ftp');
        Route::get('ftp/export', [ReportController::class, 'ftpExport'])->name('ftp.export');
    });
    Route::get('audit/{auditEvent}', [AuditController::class, 'show'])->name('audit.show');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::patch('users/{user}/status', [UserController::class, 'status'])->name('users.status');
    Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');

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

    Route::post('credentials/ssh-test', [CredentialController::class, 'testSsh'])
        ->middleware('throttle:6,1')->name('credentials.ssh-test');
    Route::post('credentials/{credential}/secret/reveal', [CredentialController::class, 'revealSecret'])
        ->middleware('throttle:6,1')->name('credentials.secret.reveal');
    Route::resource('credentials', CredentialController::class)
        ->except(['show']);

    Route::resource('backup-policies', BackupPolicyController::class)
        ->except(['show']);
    Route::post('backup-policies/{backup_policy}/associations', [DeviceBackupPolicyController::class, 'store'])->name('backup-policies.associations.store');
    Route::patch('backup-policies/{backup_policy}/associations/{association}', [DeviceBackupPolicyController::class, 'update'])->name('backup-policies.associations.update');
    Route::delete('backup-policies/{backup_policy}/associations/{association}', [DeviceBackupPolicyController::class, 'destroy'])->name('backup-policies.associations.destroy');
    Route::post('backup-policies/{backup_policy}/associations/{association}/executions', [BackupExecutionController::class, 'storeManual'])->name('backup-policies.associations.executions.store');
    Route::post('backup-policies/{backup_policy}/associations/{association}/run-a10', [BackupExecutionController::class, 'runA10'])->name('backup-policies.associations.run-a10');

    Route::get('backup-executions', [BackupExecutionController::class, 'index'])->name('backup-executions.index');
    Route::get('backup-executions/{backup_execution}/status', [BackupExecutionController::class, 'status'])->name('backup-executions.status');
    Route::get('backup-executions/{backup_execution}', [BackupExecutionController::class, 'show'])->name('backup-executions.show');
    Route::post('backup-executions/{backup_execution}/queue', [BackupExecutionController::class, 'queue'])->name('backup-executions.queue');
    Route::post('backup-executions/{backup_execution}/cancel', [BackupExecutionController::class, 'cancel'])->name('backup-executions.cancel');
    Route::get('backup-artifacts', [BackupArtifactController::class, 'index'])->name('backup-artifacts.index');
    Route::get('backup-artifacts/{backup_artifact}/versions', [BackupArtifactController::class, 'versions'])->name('backup-artifacts.versions');
    Route::get('backup-artifacts/{backup_artifact}/a10-configuration', [BackupArtifactController::class, 'a10Configuration'])->name('backup-artifacts.a10-configuration');
    Route::get('backup-artifacts/{backup_artifact}', [BackupArtifactController::class, 'show'])->name('backup-artifacts.show');
    Route::get('backup-artifacts/{backup_artifact}/download', [BackupArtifactController::class, 'download'])->name('backup-artifacts.download');
    Route::delete('backup-artifacts/{backup_artifact}', [BackupArtifactController::class, 'destroy'])->name('backup-artifacts.destroy');

    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
