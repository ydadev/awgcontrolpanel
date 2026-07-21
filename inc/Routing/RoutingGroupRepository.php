<?php

class RoutingGroupRepository
{
    public static function listGroups(): array
    {
        $stmt = DB::conn()->query('
            SELECT
                g.*,
                COALESCE(member_counts.member_count, 0) AS member_count
            FROM routing_user_groups g
            LEFT JOIN (
                SELECT group_id, COUNT(*) AS member_count
                FROM routing_user_group_members
                GROUP BY group_id
            ) member_counts ON member_counts.group_id = g.id
            ORDER BY g.name
        ');
        return $stmt->fetchAll();
    }

    public static function listUsersWithGroups(): array
    {
        $stmt = DB::conn()->query('
            SELECT
                u.id,
                u.email,
                u.name,
                u.role,
                u.status,
                m.group_id AS routing_group_id,
                g.name AS routing_group_name
            FROM users u
            LEFT JOIN routing_user_group_members m ON m.user_id = u.id
            LEFT JOIN routing_user_groups g ON g.id = m.group_id
            WHERE u.status = "active"
            ORDER BY CASE WHEN u.role = "admin" THEN 0 ELSE 1 END, u.email
        ');
        return $stmt->fetchAll();
    }

    public static function createGroup(string $name, string $description, ?int $actorUserId): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Group name is required');
        }

        $stmt = DB::conn()->prepare('
            INSERT INTO routing_user_groups (name, description, enabled, created_by)
            VALUES (?, ?, 1, ?)
        ');
        $stmt->execute([$name, trim($description), $actorUserId]);
        $id = (int) DB::conn()->lastInsertId();
        RoutingAuditService::log($actorUserId, 'routing.group.created', 'routing_user_group', $id, null, null, [
            'name' => $name,
        ]);
        return $id;
    }

    public static function replaceMembers(int $groupId, array $userIds, ?int $actorUserId): void
    {
        $pdo = DB::conn();
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static function ($id) {
            return $id > 0;
        })));

        $exists = $pdo->prepare('SELECT id FROM routing_user_groups WHERE id = ? LIMIT 1');
        $exists->execute([$groupId]);
        if (!$exists->fetchColumn()) {
            throw new InvalidArgumentException('Routing group not found');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM routing_user_group_members WHERE group_id = ?')->execute([$groupId]);

            if ($userIds) {
                $placeholders = implode(',', array_fill(0, count($userIds), '?'));
                $delete = $pdo->prepare('DELETE FROM routing_user_group_members WHERE user_id IN (' . $placeholders . ')');
                $delete->execute($userIds);

                $insert = $pdo->prepare('INSERT INTO routing_user_group_members (group_id, user_id) VALUES (?, ?)');
                foreach ($userIds as $userId) {
                    $insert->execute([$groupId, $userId]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        RoutingAuditService::log($actorUserId, 'routing.group.members_saved', 'routing_user_group', $groupId, null, null, [
            'user_ids' => $userIds,
        ]);
    }

    public static function getUserGroup(int $userId): ?array
    {
        $stmt = DB::conn()->prepare('
            SELECT g.*
            FROM routing_user_group_members m
            JOIN routing_user_groups g ON g.id = m.group_id
            WHERE m.user_id = ? AND g.enabled = 1
            LIMIT 1
        ');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function listMemberIdsByGroup(): array
    {
        $stmt = DB::conn()->query('SELECT group_id, user_id FROM routing_user_group_members ORDER BY group_id, user_id');
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $groupId = (int) $row['group_id'];
            if (!isset($result[$groupId])) {
                $result[$groupId] = [];
            }
            $result[$groupId][] = (int) $row['user_id'];
        }
        return $result;
    }

    public static function setGroupLinkPermission(int $groupId, int $serverLinkId, string $permission, int $maxRoutes, int $minimumPrefixLength, bool $allowDefaultRoute, ?int $actorUserId): void
    {
        if (!in_array($permission, ['allow', 'deny'], true)) {
            $permission = 'allow';
        }

        $maxRoutes = max(1, min(1000, $maxRoutes));
        $minimumPrefixLength = max(0, min(32, $minimumPrefixLength));

        $stmt = DB::conn()->prepare('
            INSERT INTO routing_group_link_permissions
                (group_id, server_link_id, permission, max_routes, minimum_prefix_length, allow_default_route)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                permission = VALUES(permission),
                max_routes = VALUES(max_routes),
                minimum_prefix_length = VALUES(minimum_prefix_length),
                allow_default_route = VALUES(allow_default_route),
                updated_at = NOW()
        ');
        $stmt->execute([
            $groupId,
            $serverLinkId,
            $permission,
            $maxRoutes,
            $minimumPrefixLength,
            $allowDefaultRoute ? 1 : 0,
        ]);

        RoutingAuditService::log($actorUserId, 'routing.group.permission_saved', 'routing_user_group', $groupId, null, null, [
            'server_link_id' => $serverLinkId,
            'permission' => $permission,
        ]);
    }

    public static function listGroupPermissions(): array
    {
        $stmt = DB::conn()->query('
            SELECT
                p.*,
                g.name AS group_name,
                l.name AS link_name,
                si.name AS ingress_server_name,
                se.name AS egress_server_name
            FROM routing_group_link_permissions p
            JOIN routing_user_groups g ON g.id = p.group_id
            JOIN routing_server_links l ON l.id = p.server_link_id
            JOIN routing_ingresses i ON i.id = l.ingress_id
            JOIN vpn_servers si ON si.id = i.server_id
            JOIN vpn_servers se ON se.id = l.egress_server_id
            ORDER BY g.name, si.name, se.name
        ');
        return $stmt->fetchAll();
    }

    public static function getGroupLinkPermission(int $groupId, int $serverLinkId): ?array
    {
        $stmt = DB::conn()->prepare('
            SELECT p.*, l.enabled AS link_enabled, l.user_access_mode
            FROM routing_group_link_permissions p
            JOIN routing_server_links l ON l.id = p.server_link_id
            WHERE p.group_id = ? AND p.server_link_id = ?
            LIMIT 1
        ');
        $stmt->execute([$groupId, $serverLinkId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function listAllowedLinksForGroup(int $groupId): array
    {
        $stmt = DB::conn()->prepare('
            SELECT
                l.*,
                si.name AS ingress_server_name,
                se.name AS egress_server_name,
                p.max_routes,
                p.minimum_prefix_length,
                p.allow_default_route
            FROM routing_group_link_permissions p
            JOIN routing_server_links l ON l.id = p.server_link_id
            JOIN routing_ingresses i ON i.id = l.ingress_id
            JOIN vpn_servers si ON si.id = i.server_id
            JOIN vpn_servers se ON se.id = l.egress_server_id
            WHERE p.group_id = ?
              AND p.permission = "allow"
              AND l.enabled = 1
              AND l.user_access_mode = "user_allowed"
            ORDER BY si.name, se.name
        ');
        $stmt->execute([$groupId]);
        return $stmt->fetchAll();
    }
}
