<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';

Config::load(__DIR__ . '/../.env');

$once = in_array('--once', $argv, true);

do {
    try {
        $pdo = DB::conn();
        $pdo->exec('
            INSERT INTO routing_server_state (server_id, last_reconcile_at)
            SELECT s.id, NULL
            FROM vpn_servers s
            LEFT JOIN routing_server_state rs ON rs.server_id = s.id
            WHERE s.status = "active" AND rs.server_id IS NULL
        ');
        $pdo->exec('
            INSERT INTO routing_outbox (server_id, event_type, urgency, payload)
            SELECT s.id, "scheduled_reconcile", "normal", JSON_OBJECT("source", "routing_reconcile")
            FROM vpn_servers s
            JOIN routing_server_state rs ON rs.server_id = s.id
            WHERE s.status = "active"
              AND (rs.last_reconcile_at IS NULL OR rs.last_reconcile_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
        ');
        $pdo->exec('UPDATE routing_server_state SET last_reconcile_at = NOW() WHERE last_reconcile_at IS NULL OR last_reconcile_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)');
    } catch (Throwable $e) {
        error_log('routing_reconcile failed: ' . $e->getMessage());
        if ($once) {
            exit(1);
        }
    }

    if (!$once) {
        sleep(300);
    }
} while (!$once);
