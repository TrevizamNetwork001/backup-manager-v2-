<?php

return [
    // Backlog thresholds for pending/retry_wait counts. Starting point, not
    // measured against real traffic yet — revisit once there's production
    // history to calibrate against (see docs/ENGINE_HEALTH.md).
    'backlog_warning' => (int) env('HEALTH_BACKLOG_WARNING', 6),
    'backlog_critical' => (int) env('HEALTH_BACKLOG_CRITICAL', 21),

    // Windows used by the queue/failure checks.
    'recent_window_hours' => (int) env('HEALTH_RECENT_WINDOW_HOURS', 24),
    'recent_terminal_window_days' => (int) env('HEALTH_TERMINAL_WINDOW_DAYS', 7),

    // A worker/scheduler heartbeat older than this (but younger than the
    // engine's own stale threshold) is 'warning'; older still is 'critical'.
    'scheduler_warning_minutes' => (int) env('HEALTH_SCHEDULER_WARNING_MINUTES', 3),
    'scheduler_critical_minutes' => (int) env('HEALTH_SCHEDULER_CRITICAL_MINUTES', 10),

    // Success-rate thresholds for the 'failure' check (percent of terminal
    // executions, in recent_window_hours, that succeeded).
    'success_rate_warning_percent' => (int) env('HEALTH_SUCCESS_RATE_WARNING_PERCENT', 90),
    'success_rate_critical_percent' => (int) env('HEALTH_SUCCESS_RATE_CRITICAL_PERCENT', 70),

    // Storage capacity (percentage used).
    'storage_warning_percent' => (int) env('HEALTH_STORAGE_WARNING_PERCENT', 80),
    'storage_critical_percent' => (int) env('HEALTH_STORAGE_CRITICAL_PERCENT', 90),

    // Device backup freshness: how long after the expected cadence a device
    // is still considered 'healthy' before sliding to 'warning'/'critical'.
    // Manual-only devices are never judged on freshness (see
    // DeviceBackupHealth) — only these two scheduled cadences use it.
    'device_daily_warning_hours' => (int) env('HEALTH_DEVICE_DAILY_WARNING_HOURS', 30),
    'device_daily_critical_hours' => (int) env('HEALTH_DEVICE_DAILY_CRITICAL_HOURS', 48),
    'device_weekly_warning_hours' => (int) env('HEALTH_DEVICE_WEEKLY_WARNING_HOURS', 192),  // 8 days
    'device_weekly_critical_hours' => (int) env('HEALTH_DEVICE_WEEKLY_CRITICAL_HOURS', 240), // 10 days

    'device_ftp_warning_grace_hours' => (int) env('HEALTH_DEVICE_FTP_WARNING_GRACE_HOURS', 6),
    'device_ftp_critical_grace_hours' => (int) env('HEALTH_DEVICE_FTP_CRITICAL_GRACE_HOURS', 24),

    // Consecutive failed/timed_out executions (most recent first) before a
    // device is 'critical' regardless of freshness.
    'device_consecutive_failures_critical' => (int) env('HEALTH_DEVICE_CONSECUTIVE_FAILURES', 3),

    // How many of a device's most recent executions to look at when
    // computing the consecutive-failure streak (bounded, not full history).
    'device_recent_executions_sample' => 5,

    // FTP: a claimed-but-not-finalized upload older than this is "stale
    // processing" — separate from the engine's own MAX_PROCESSING_RETRIES
    // cap, this is a health signal, not a retry policy.
    'ftp_processing_stale_minutes' => (int) env('HEALTH_FTP_PROCESSING_STALE_MINUTES', 15),

    // Expensive checks (storage free space, Redis ping) are cached this many
    // seconds so the health page stays cheap under repeated refreshes. A
    // critical stale-job count is never masked by this — that check always
    // reads live (see EngineHealth::report()).
    'cache_seconds' => (int) env('HEALTH_CACHE_SECONDS', 30),
];
