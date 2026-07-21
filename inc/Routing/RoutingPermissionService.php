<?php

class RoutingPermissionService
{
    public static function requirePermission(string $permission): void
    {
        requireAuth();
        if (!Auth::can($permission)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }

    public static function userCanUseLink(int $userId, int $serverLinkId): bool
    {
        $group = RoutingGroupRepository::getUserGroup($userId);
        if ($group) {
            $permission = RoutingGroupRepository::getGroupLinkPermission((int) $group['id'], $serverLinkId);
            return $permission !== null
                && ($permission['permission'] ?? '') === 'allow'
                && (int) ($permission['link_enabled'] ?? 0) === 1
                && ($permission['user_access_mode'] ?? '') === 'user_allowed';
        }

        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT l.user_access_mode, p.permission
            FROM routing_server_links l
            LEFT JOIN routing_user_link_permissions p
                ON p.server_link_id = l.id AND p.user_id = ?
            WHERE l.id = ? AND l.enabled = 1
            LIMIT 1
        ');
        $stmt->execute([$userId, $serverLinkId]);
        $row = $stmt->fetch();

        if (!$row || ($row['user_access_mode'] ?? '') !== 'user_allowed') {
            return false;
        }

        return ($row['permission'] ?? '') === 'allow';
    }

    public static function getUserLinkLimits(int $userId, int $serverLinkId): array
    {
        $group = RoutingGroupRepository::getUserGroup($userId);
        if ($group) {
            $permission = RoutingGroupRepository::getGroupLinkPermission((int) $group['id'], $serverLinkId);
            if ($permission
                && ($permission['permission'] ?? '') === 'allow'
                && (int) ($permission['link_enabled'] ?? 0) === 1
                && ($permission['user_access_mode'] ?? '') === 'user_allowed') {
                return $permission;
            }
        }

        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT max_routes, minimum_prefix_length, allow_default_route
            FROM routing_user_link_permissions
            WHERE user_id = ? AND server_link_id = ? AND permission = "allow"
            LIMIT 1
        ');
        $stmt->execute([$userId, $serverLinkId]);
        $row = $stmt->fetch();

        return $row ?: [
            'max_routes' => 100,
            'minimum_prefix_length' => 8,
            'allow_default_route' => 0,
        ];
    }
}
