<?php

/** Read-only PostgreSQL sampler; only the isolated PERF socket is accepted. */
$socket = getenv('DB_HOST');
$root = getenv('PERF_WORKSPACE');
$database = getenv('DB_DATABASE');
if (getenv('APP_ENV') !== 'testing' || ! preg_match('~/tmp/bm-perf-1-[a-zA-Z0-9_-]+/socket\z~D', $socket ?: '') ||
    ! preg_match('~\A/tmp/bm-perf-1-[a-zA-Z0-9_-]+\z~D', $root ?: '') ||
    ! preg_match('/\Abm_perf_1_[a-z0-9_]+\z/D', $database ?: '')) {
    throw new RuntimeException('Monitor requires isolated PERF environment.');
}
$db = new PDO('pgsql:host='.$socket.';port=55432;dbname=postgres', 'perf', '');
if ($db->query('SHOW data_directory')->fetchColumn() !== dirname($socket).'/pgdata') {
    throw new RuntimeException('Unexpected cluster.');
}
$query = $db->prepare("SELECT count(*) AS connections, count(*) FILTER (WHERE wait_event_type='Lock') AS waiters
    FROM pg_stat_activity WHERE datname = ?");
$peak = $waiters = $samples = 0;
$start = microtime(true);
touch($root.'/monitor-ready');
do {
    $query->execute([$database]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    $peak = max($peak, (int) $row['connections']);
    $waiters = max($waiters, (int) $row['waiters']);
    $samples++;
    usleep(50000);
} while (! file_exists($root.'/monitor-stop') && microtime(true) - $start < 240);
echo json_encode(['peak_workload_connections' => $peak, 'peak_lock_waiters' => $waiters,
    'samples' => $samples, 'interval_ms' => 50, 'seconds' => microtime(true) - $start]).PHP_EOL;
