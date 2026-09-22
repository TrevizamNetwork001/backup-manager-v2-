<?php

return [
    'storage_root' => env('BACKUP_STORAGE_ROOT', '/data/backups'),
    'max_artifact_bytes' => 8 * 1024 * 1024,
    'engine_stale_seconds' => (int) env('BACKUP_ENGINE_STALE_SECONDS', 300),
    'scheduler_grace_minutes' => (int) env('BACKUP_SCHEDULER_GRACE_MINUTES', 5),
];
