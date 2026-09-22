<?php

return [
    'storage_root' => env('BACKUP_STORAGE_ROOT', '/data/backups'),
    'max_artifact_bytes' => 8 * 1024 * 1024,
];
