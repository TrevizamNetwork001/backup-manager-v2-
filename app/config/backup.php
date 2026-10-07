<?php

return [
    'storage_root' => env('BACKUP_STORAGE_ROOT', '/data/backups'),
    'ftp_root' => env('BACKUP_FTP_ROOT', '/data/ftp'),
    'max_artifact_bytes' => 8 * 1024 * 1024,
    'a10_max_bytes' => 64 * 1024 * 1024,
    'a10_enabled' => env('BACKUP_A10_ENABLED', false),
    // Login events written by the FTP container (read-only volume) feed the
    // "login recusado" alert: N refusals of one account inside the window.
    'ftp_log_dir' => env('BACKUP_FTP_LOG_DIR', '/var/log/backup-ftp'),
    'ftp_auth_threshold' => (int) env('BACKUP_FTP_AUTH_THRESHOLD', 3),
    'ftp_auth_window_minutes' => (int) env('BACKUP_FTP_AUTH_WINDOW_MINUTES', 30),
    'ftp_max_bytes' => (int) env('BACKUP_FTP_MAX_BYTES', 8 * 1024 * 1024),
    'engine_stale_seconds' => (int) env('BACKUP_ENGINE_STALE_SECONDS', 300),
    // Overall wall-clock budget for a single execution, independent of heartbeat
    // freshness — catches a worker that keeps heartbeating but is stuck in a
    // loop. Must stay comfortably above engine_stale_seconds.
    'engine_execution_timeout_seconds' => (int) env('BACKUP_ENGINE_EXECUTION_TIMEOUT_SECONDS', 1800),
    'engine_max_attempts' => (int) env('BACKUP_ENGINE_MAX_ATTEMPTS', 3),
    // Backoff applied when scheduling a retry after attempt N fails, keyed by
    // the *next* attempt number. Anything beyond the highest key uses the last
    // value. Kept intentionally small/explicit rather than a formula — easy to
    // reason about and to change without touching code.
    'engine_retry_backoff_seconds' => [2 => 60, 3 => 300, 4 => 900],
    'scheduler_grace_minutes' => (int) env('BACKUP_SCHEDULER_GRACE_MINUTES', 5),
    'retention_enabled' => env('BACKUP_RETENTION_ENABLED', false),
    'retention_time' => env('BACKUP_RETENTION_TIME', '04:30'),
    // Address shown to the operator for the OLT; independent of Pure-FTPd's passive address.
    'ftp_host' => env('BACKUP_FTP_HOST', ''),
    'ftp_passive_address' => env('BACKUP_FTP_PASSIVE_ADDRESS') ?: env('BACKUP_FTP_PUBLIC_IP', ''),
    // Where the Python engine drops its own health snapshot (ENGINE-3) — see
    // docs/ENGINE_HEALTH.md. Laravel has no other way to observe the engine
    // process: no shared filesystem/venv access, no HTTP endpoint by design.
    // Default matches the path compose.yml mounts inside the app container.
    'engine_health_snapshot_path' => env('BACKUP_ENGINE_HEALTH_SNAPSHOT_PATH', storage_path('app/engine-health/snapshot.json')),
    'engine_health_snapshot_stale_seconds' => (int) env('BACKUP_ENGINE_HEALTH_SNAPSHOT_STALE_SECONDS', 90),
];
