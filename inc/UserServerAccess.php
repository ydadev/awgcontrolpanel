<?php

require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/VpnClient.php';

/**
 * User-to-server access control.
 *
 * Admins are handled by route/controller checks. This class stores explicit
 * access for regular users and revokes their active clients when access is
 * removed from a server.
 */
class UserServerAccess
{
    public static function canViewServer(int $userId, int $serverId): bool
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT 1
            FROM user_server_access
            WHERE user_id = ? AND server_id = ? AND can_view = 1
            LIMIT 1
        ');
        $stmt->execute([$userId, $serverId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function canCreateClients(int $userId, int $serverId): bool
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT 1
            FROM user_server_access
            WHERE user_id = ? AND server_id = ? AND can_view = 1 AND can_create_clients = 1
            LIMIT 1
        ');
        $stmt->execute([$userId, $serverId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function listServersForUser(int $userId): array
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT s.*, usa.can_create_clients
            FROM user_server_access usa
            JOIN vpn_servers s ON s.id = usa.server_id
            WHERE usa.user_id = ? AND usa.can_view = 1
            ORDER BY s.created_at DESC
        ');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function mapForUsers(): array
    {
        $pdo = DB::conn();
        $rows = $pdo->query('
            SELECT user_id, server_id, can_view, can_create_clients
            FROM user_server_access
        ')->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $serverId = (int) $row['server_id'];
            if (!isset($map[$userId])) {
                $map[$userId] = [];
            }
            $map[$userId][$serverId] = $row;
        }
        return $map;
    }

    public static function replaceForUser(int $userId, array $serverIds, array $createClientServerIds): void
    {
        $pdo = DB::conn();
        $serverIds = self::normalizeIds($serverIds);
        $createClientServerIds = array_values(array_intersect(self::normalizeIds($createClientServerIds), $serverIds));

        $stmt = $pdo->prepare('SELECT server_id FROM user_server_access WHERE user_id = ? AND can_view = 1');
        $stmt->execute([$userId]);
        $currentServerIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $removedServerIds = array_values(array_diff($currentServerIds, $serverIds));

        foreach ($removedServerIds as $serverId) {
            self::revokeUserServerClients($userId, (int) $serverId);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM user_server_access WHERE user_id = ?');
            $stmt->execute([$userId]);

            if (!empty($serverIds)) {
                $insert = $pdo->prepare('
                    INSERT INTO user_server_access (user_id, server_id, can_view, can_create_clients)
                    VALUES (?, ?, 1, ?)
                ');
                foreach ($serverIds as $serverId) {
                    $insert->execute([
                        $userId,
                        $serverId,
                        in_array($serverId, $createClientServerIds, true) ? 1 : 0,
                    ]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function revokeUserServerClients(int $userId, int $serverId): int
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT id
            FROM vpn_clients
            WHERE user_id = ? AND server_id = ? AND status = "active"
            ORDER BY id
        ');
        $stmt->execute([$userId, $serverId]);
        $clientIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $revoked = 0;
        foreach ($clientIds as $clientId) {
            try {
                $client = new VpnClient($clientId);
                if ($client->revoke()) {
                    $revoked++;
                }
            } catch (Throwable $e) {
                error_log('Failed to revoke client after server access removal: ' . $e->getMessage());
            }
        }

        $stmt = $pdo->prepare('
            UPDATE vpn_clients
            SET status = "disabled"
            WHERE user_id = ? AND server_id = ? AND status <> "disabled"
        ');
        $stmt->execute([$userId, $serverId]);

        return max($revoked, (int) $stmt->rowCount());
    }

    private static function normalizeIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $normalized[$id] = $id;
            }
        }
        return array_values($normalized);
    }
}
