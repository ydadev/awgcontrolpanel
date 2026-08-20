<?php

$root = dirname(__DIR__);
$monitor = file_get_contents($root . '/bin/monitor_metrics.sh');
$collector = file_get_contents($root . '/bin/collect_metrics.php');
$monitoring = file_get_contents($root . '/inc/ServerMonitoring.php');
$dockerfile = file_get_contents($root . '/Dockerfile');

$required = [
    [$monitor, 'collect_metrics.heartbeat', 'Monitor must check the collector heartbeat'],
    [$monitor, 'heartbeat is stale - restarting it', 'Monitor must restart a stale collector'],
    [$monitor, '/bin/kill -TERM -- "-$PID"', 'Monitor must stop the collector process group'],
    [$collector, 'touch($heartbeatFile)', 'Collector must refresh its heartbeat'],
    [$monitoring, 'timeout --signal=TERM', 'SSH metric commands must have a hard timeout'],
    [$monitoring, 'wireGuardDumpCache', 'WireGuard dumps must be cached per collection cycle'],
];

foreach ($required as [$content, $fragment, $message]) {
    if (!str_contains($content, $fragment)) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

if (preg_match('/&&\s+crontab\s+\/etc\/cron\.d\/amnezia-cron/', $dockerfile)) {
    fwrite(STDERR, "System cron file must not also be installed as a user crontab\n");
    exit(1);
}

echo "metrics_collector_safety_test: ok\n";
