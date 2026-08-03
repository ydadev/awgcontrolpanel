<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/UserServerAccess.php';
require_once __DIR__ . '/../inc/VpnServer.php';
require_once __DIR__ . '/../inc/Routing/RoutingValidator.php';
require_once __DIR__ . '/../inc/Routing/RoutingAuditService.php';
require_once __DIR__ . '/../inc/Routing/RoutingRouteTargetService.php';

Config::load(__DIR__ . '/../.env');

$selector = trim((string) ($argv[1] ?? ''));
if ($selector === '') {
    fwrite(STDERR, "Usage: php bin/routing_apply_shared.php --all|TARGET_KEY\n");
    exit(2);
}

$pdo = DB::conn();
$actorId = $pdo->query(
    'SELECT id FROM users WHERE role = "admin" ORDER BY id LIMIT 1'
)->fetchColumn();
if ($actorId === false) {
    fwrite(STDERR, "No administrator account is available for the audit record\n");
    exit(1);
}

if ($selector === '--all') {
    $targetIds = $pdo->query(
        'SELECT id FROM routing_route_targets WHERE enabled = 1 ORDER BY priority, id'
    )->fetchAll(PDO::FETCH_COLUMN);
} else {
    $stmt = $pdo->prepare(
        'SELECT id FROM routing_route_targets
         WHERE target_key = ? AND enabled = 1
         LIMIT 1'
    );
    $stmt->execute([$selector]);
    $targetId = $stmt->fetchColumn();
    $targetIds = $targetId === false ? [] : [$targetId];
}

if (!$targetIds) {
    fwrite(STDERR, "No matching shared route target found\n");
    exit(1);
}

foreach ($targetIds as $targetId) {
    try {
        $result = RoutingRouteTargetService::apply((int) $targetId, (int) $actorId);
        printf(
            "target=%d entries=%d hash=%s status=applied\n",
            (int) $result['target_id'],
            (int) $result['entry_count'],
            (string) $result['hash']
        );
    } catch (Throwable $e) {
        fwrite(STDERR, 'target=' . (int) $targetId . ' status=failed error=' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
