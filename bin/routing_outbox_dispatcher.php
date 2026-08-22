<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/Modules/FeatureModuleRegistry.php';

Config::load(__DIR__ . '/../.env');
FeatureModuleRegistry::boot(__DIR__ . '/../modules');
if (!FeatureModuleRegistry::isEnabled('routing')) {
    echo "Routing module is disabled\n";
    exit(0);
}

try {
    $count = DB::conn()->exec('UPDATE routing_outbox SET status = "queued" WHERE status = "pending" AND available_at <= NOW()');
    echo "Queued {$count} routing event(s)\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
