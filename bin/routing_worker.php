<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/Routing/RoutingValidator.php';
require_once __DIR__ . '/../inc/Routing/RoutingCompiler.php';
require_once __DIR__ . '/../inc/Routing/RoutingConfigBuilder.php';
require_once __DIR__ . '/../inc/Routing/RoutingDeliveryService.php';

Config::load(__DIR__ . '/../.env');

$once = in_array('--once', $argv, true);

do {
    try {
        $pdo = DB::conn();
        $stmt = $pdo->query('
            SELECT server_id, MIN(id) AS first_event_id
            FROM routing_outbox
            WHERE status IN ("pending","queued") AND available_at <= NOW()
            GROUP BY server_id
            ORDER BY first_event_id
            LIMIT 20
        ');
        $serverIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        foreach ($serverIds as $serverId) {
            $config = RoutingConfigBuilder::buildForServer($serverId);
            $stateStmt = $pdo->prepare('SELECT applied_hash FROM routing_server_state WHERE server_id = ? LIMIT 1');
            $stateStmt->execute([$serverId]);
            $appliedHash = $stateStmt->fetchColumn();

            if ($appliedHash && hash_equals((string) $appliedHash, $config['configuration_hash'])) {
                $mark = $pdo->prepare('UPDATE routing_outbox SET status = "processed", processed_at = NOW() WHERE server_id = ? AND status IN ("pending","queued")');
                $mark->execute([$serverId]);
                echo '[' . date('c') . "] routing config already applied for server {$serverId}\n";
                continue;
            }

            RoutingDeliveryService::createRevision($serverId);
            $mark = $pdo->prepare('UPDATE routing_outbox SET status = "processed", processed_at = NOW() WHERE server_id = ? AND status IN ("pending","queued")');
            $mark->execute([$serverId]);
            echo '[' . date('c') . "] routing revision created for server {$serverId}\n";
        }
    } catch (Throwable $e) {
        error_log('routing_worker failed: ' . $e->getMessage());
        if ($once) {
            exit(1);
        }
    }

    if (!$once) {
        sleep(3);
    }
} while (!$once);
