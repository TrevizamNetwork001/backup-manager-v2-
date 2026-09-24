<?php

namespace App\Support;

/**
 * Named conditions derived from a health report — the vocabulary a future
 * notification system (Telegram/e-mail) will subscribe to. This phase only
 * *computes* which conditions are currently active (EngineHealth::alerts());
 * it never sends anything. Health (current computed state) and Alert (a
 * condition that could someday trigger a notification) are deliberately
 * kept as separate concepts — see docs/ENGINE_HEALTH.md.
 */
enum AlertCondition: string
{
    case EngineDown = 'engine_down';
    case WorkerStale = 'worker_stale';
    case SchedulerStale = 'scheduler_stale';
    case QueueBacklog = 'queue_backlog';
    case StaleJobs = 'stale_jobs';
    case StorageWarning = 'storage_warning';
    case StorageCritical = 'storage_critical';
    case RepeatedDeviceFailures = 'repeated_device_failures';
    case FtpProcessingStale = 'ftp_processing_stale';
    case RetentionFailed = 'retention_failed';
}
