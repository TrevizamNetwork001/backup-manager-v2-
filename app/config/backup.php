<?php

return [
    'storage_root' => env('BACKUP_STORAGE_ROOT', '/data/backups'),
    'max_artifact_bytes' => 8 * 1024 * 1024,
    'ftp_max_bytes' => (int) env('BACKUP_FTP_MAX_BYTES', 8 * 1024 * 1024),
    'engine_stale_seconds' => (int) env('BACKUP_ENGINE_STALE_SECONDS', 300),
    'scheduler_grace_minutes' => (int) env('BACKUP_SCHEDULER_GRACE_MINUTES', 5),
    'retention_enabled' => env('BACKUP_RETENTION_ENABLED', false),
    'retention_time' => env('BACKUP_RETENTION_TIME', '04:30'),
    // Address shown to the operator for the OLT; independent of Pure-FTPd's passive address.
    'ftp_host' => env('BACKUP_FTP_HOST', ''),
    'ftp_passive_address' => env('BACKUP_FTP_PASSIVE_ADDRESS') ?: env('BACKUP_FTP_PUBLIC_IP', ''),
];
