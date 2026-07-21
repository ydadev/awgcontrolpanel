<?php

class RoutingRepository
{
    public static function dashboard(): array
    {
        $pdo = DB::conn();
        return [
            'ingresses' => (int) $pdo->query('SELECT COUNT(*) FROM routing_ingresses')->fetchColumn(),
            'links' => (int) $pdo->query('SELECT COUNT(*) FROM routing_server_links')->fetchColumn(),
            'ip_lists' => (int) $pdo->query('SELECT COUNT(*) FROM routing_ip_lists')->fetchColumn(),
            'profiles' => (int) $pdo->query('SELECT COUNT(*) FROM routing_profiles')->fetchColumn(),
            'groups' => (int) $pdo->query('SELECT COUNT(*) FROM routing_user_groups')->fetchColumn(),
            'pending_revisions' => (int) $pdo->query('SELECT COUNT(*) FROM routing_config_revisions WHERE status IN ("pending","delivering")')->fetchColumn(),
        ];
    }

    public static function listServersWithProtocols(): array
    {
        $stmt = DB::conn()->query('
            SELECT
                s.id AS server_id,
                s.name AS server_name,
                s.host,
                sp.id AS server_protocol_id,
                sp.routing_enabled,
                sp.routing_interface_name,
                p.name AS protocol_name,
                p.slug,
                p.supports_policy_routing
            FROM vpn_servers s
            JOIN server_protocols sp ON sp.server_id = s.id
            JOIN protocols p ON p.id = sp.protocol_id
            WHERE p.supports_policy_routing = 1
            ORDER BY s.name, p.name
        ');
        return $stmt->fetchAll();
    }

    public static function listIngresses(): array
    {
        $stmt = DB::conn()->query('
            SELECT ri.*, s.name AS server_name, s.host, p.name AS protocol_name, pool.canonical_cidr AS pool_cidr
            FROM routing_ingresses ri
            JOIN vpn_servers s ON s.id = ri.server_id
            JOIN server_protocols sp ON sp.id = ri.server_protocol_id
            JOIN protocols p ON p.id = sp.protocol_id
            LEFT JOIN routing_ip_pools pool ON pool.id = ri.ip_pool_id
            ORDER BY s.name, p.name
        ');
        return $stmt->fetchAll();
    }

    public static function ensureIngress(int $serverId, int $serverProtocolId, string $poolCidr, string $interfaceName, ?int $actorUserId): int
    {
        $normalized = RoutingValidator::normalizeIpv4Cidr($poolCidr);
        $pdo = DB::conn();
        $pdo->beginTransaction();

        try {
            $poolStmt = $pdo->prepare('
                INSERT INTO routing_ip_pools
                (pool_type, server_id, server_protocol_id, family, network, prefix_length, canonical_cidr, status)
                VALUES ("ingress", ?, ?, ?, ?, ?, ?, "active")
                ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = NOW()
            ');
            $poolStmt->execute([
                $serverId,
                $serverProtocolId,
                $normalized['family'],
                $normalized['network'],
                $normalized['prefix_length'],
                $normalized['canonical_cidr'],
            ]);

            $poolId = (int) $pdo->lastInsertId();
            if ($poolId === 0) {
                $find = $pdo->prepare('SELECT id FROM routing_ip_pools WHERE family = ? AND network = ? AND prefix_length = ? LIMIT 1');
                $find->execute([$normalized['family'], $normalized['network'], $normalized['prefix_length']]);
                $poolId = (int) $find->fetchColumn();
            }

            $stmt = $pdo->prepare('
                INSERT INTO routing_ingresses
                (server_id, server_protocol_id, ip_pool_id, interface_name, enabled, status)
                VALUES (?, ?, ?, ?, 1, "pending")
                ON DUPLICATE KEY UPDATE ip_pool_id = VALUES(ip_pool_id), interface_name = VALUES(interface_name), enabled = 1, status = "pending", updated_at = NOW()
            ');
            $stmt->execute([$serverId, $serverProtocolId, $poolId, $interfaceName]);

            $ingressId = (int) $pdo->lastInsertId();
            if ($ingressId === 0) {
                $find = $pdo->prepare('SELECT id FROM routing_ingresses WHERE server_protocol_id = ? LIMIT 1');
                $find->execute([$serverProtocolId]);
                $ingressId = (int) $find->fetchColumn();
            }

            $pdo->prepare('UPDATE server_protocols SET routing_enabled = 1, routing_interface_name = ? WHERE id = ?')
                ->execute([$interfaceName, $serverProtocolId]);

            $pdo->commit();
            RoutingAuditService::log($actorUserId, 'routing.ingress.saved', 'routing_ingress', $ingressId, $serverId, null, [
                'pool' => $normalized['canonical_cidr'],
                'interface' => $interfaceName,
            ]);
            self::enqueueServer($serverId, 'ingress_changed', 'normal');
            return $ingressId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function listLinks(): array
    {
        $stmt = DB::conn()->query('
            SELECT l.*, i.server_id AS ingress_server_id, si.name AS ingress_server_name, se.name AS egress_server_name
            FROM routing_server_links l
            JOIN routing_ingresses i ON i.id = l.ingress_id
            JOIN vpn_servers si ON si.id = i.server_id
            JOIN vpn_servers se ON se.id = l.egress_server_id
            ORDER BY l.created_at DESC
        ');
        return $stmt->fetchAll();
    }

    public static function createLink(array $data, ?int $actorUserId): int
    {
        $stmt = DB::conn()->prepare('
            INSERT INTO routing_server_links
            (ingress_id, egress_server_id, name, endpoint_host, endpoint_port, user_access_mode, enabled, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 0, "provisioning", ?)
        ');
        $stmt->execute([
            (int) $data['ingress_id'],
            (int) $data['egress_server_id'],
            trim((string) $data['name']),
            trim((string) ($data['endpoint_host'] ?? '')),
            !empty($data['endpoint_port']) ? (int) $data['endpoint_port'] : null,
            in_array($data['user_access_mode'] ?? '', ['admin_only', 'user_allowed', 'disabled'], true) ? $data['user_access_mode'] : 'admin_only',
            $actorUserId,
        ]);
        $id = (int) DB::conn()->lastInsertId();
        RoutingAuditService::log($actorUserId, 'routing.link.created', 'routing_server_link', $id, null, null, $data);
        return $id;
    }

    public static function listIpLists(?int $userId = null, bool $includeAdmin = true): array
    {
        $sql = 'SELECT l.*, u.email AS owner_email FROM routing_ip_lists l LEFT JOIN users u ON u.id = l.owner_user_id';
        $args = [];
        if ($userId !== null && !$includeAdmin) {
            $sql .= ' WHERE l.owner_user_id = ? AND l.scope = "user"';
            $args[] = $userId;
        }
        $sql .= ' ORDER BY l.created_at DESC';
        $stmt = DB::conn()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    public static function createIpList(string $name, string $scope, ?int $ownerUserId, ?int $actorUserId): int
    {
        if (!in_array($scope, ['system', 'admin', 'user'], true)) {
            $scope = 'admin';
        }
        $stmt = DB::conn()->prepare('
            INSERT INTO routing_ip_lists (owner_user_id, name, scope, created_by)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$ownerUserId, trim($name), $scope, $actorUserId]);
        $id = (int) DB::conn()->lastInsertId();
        RoutingAuditService::log($actorUserId, 'routing.ip_list.created', 'routing_ip_list', $id, null, null, ['name' => $name, 'scope' => $scope]);
        return $id;
    }

    public static function addIpListEntry(int $listId, string $cidr): int
    {
        $normalized = RoutingValidator::normalizeIpv4Cidr($cidr);
        $stmt = DB::conn()->prepare('
            INSERT INTO routing_ip_list_entries (ip_list_id, family, network, prefix_length, canonical_cidr)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE canonical_cidr = VALUES(canonical_cidr)
        ');
        $stmt->execute([
            $listId,
            $normalized['family'],
            $normalized['network'],
            $normalized['prefix_length'],
            $normalized['canonical_cidr'],
        ]);
        return (int) DB::conn()->lastInsertId();
    }

    public static function enqueueServer(int $serverId, string $eventType, string $urgency = 'normal', array $payload = []): void
    {
        $stmt = DB::conn()->prepare('
            INSERT INTO routing_outbox (server_id, event_type, urgency, payload)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $serverId,
            $eventType,
            $urgency === 'urgent' ? 'urgent' : 'normal',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }
}
