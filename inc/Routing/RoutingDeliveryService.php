<?php

class RoutingDeliveryService
{
    public static function createRevision(int $serverId): int
    {
        $config = RoutingConfigBuilder::buildForServer($serverId);
        $pdo = DB::conn();
        $pdo->beginTransaction();
        try {
            $stateStmt = $pdo->prepare('
                INSERT INTO routing_server_state (server_id, desired_version, desired_hash)
                VALUES (?, 1, ?)
                ON DUPLICATE KEY UPDATE desired_version = desired_version + 1, desired_hash = VALUES(desired_hash), updated_at = NOW()
            ');
            $stateStmt->execute([$serverId, $config['configuration_hash']]);

            $versionStmt = $pdo->prepare('SELECT desired_version FROM routing_server_state WHERE server_id = ? LIMIT 1');
            $versionStmt->execute([$serverId]);
            $version = (int) $versionStmt->fetchColumn();
            $config['version'] = $version;

            $revisionStmt = $pdo->prepare('
                INSERT INTO routing_config_revisions
                (server_id, version, configuration_hash, configuration_json, status)
                VALUES (?, ?, ?, ?, "pending")
            ');
            $revisionStmt->execute([
                $serverId,
                $version,
                $config['configuration_hash'],
                json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            $revisionId = (int) $pdo->lastInsertId();

            $jobStmt = $pdo->prepare('INSERT INTO routing_delivery_jobs (server_id, revision_id, status, next_attempt_at) VALUES (?, ?, "pending", NOW())');
            $jobStmt->execute([$serverId, $revisionId]);

            $pdo->commit();
            return $revisionId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
