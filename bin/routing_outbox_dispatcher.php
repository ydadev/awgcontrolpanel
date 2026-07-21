<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';

Config::load(__DIR__ . '/../.env');

try {
    $count = DB::conn()->exec('UPDATE routing_outbox SET status = "queued" WHERE status = "pending" AND available_at <= NOW()');
    echo "Queued {$count} routing event(s)\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
