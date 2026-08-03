<?php

class RoutingGroupRepository
{
    public static function listGroups(): array
    {
        $stmt = DB::conn()->query(
            'SELECT
                routing_group.*,
                COALESCE(member_counts.member_count, 0) AS member_count
             FROM routing_user_groups routing_group
             LEFT JOIN (
                SELECT group_id, COUNT(*) AS member_count
                FROM routing_user_group_members
                GROUP BY group_id
             ) member_counts ON member_counts.group_id = routing_group.id
             WHERE routing_group.enabled = 1
             ORDER BY routing_group.name'
        );
        return $stmt->fetchAll();
    }

    public static function listUsersWithGroups(): array
    {
        $stmt = DB::conn()->query(
            'SELECT
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
             ORDER BY CASE WHEN u.role = "admin" THEN 0 ELSE 1 END, u.email'
        );
        return $stmt->fetchAll();
    }

    public static function ensureDefaultGroup(): int
    {
        $pdo = DB::conn();
        $stmt = $pdo->query(
            'SELECT id FROM routing_user_groups
             WHERE name = "default"
             LIMIT 1'
        );
        $groupId = $stmt->fetchColumn();
        if ($groupId !== false) {
            $pdo->prepare(
                'UPDATE routing_user_groups
                 SET enabled = 1,
                     description = "Общая группа маршрутизации для всех пользователей."
                 WHERE id = ?'
            )->execute([(int) $groupId]);
            return (int) $groupId;
        }

        $insert = $pdo->prepare(
            'INSERT INTO routing_user_groups (name, description, enabled, created_by)
             VALUES ("default", "Общая группа маршрутизации для всех пользователей.", 1, NULL)'
        );
        $insert->execute();
        return (int) $pdo->lastInsertId();
    }

    public static function assignAllUsersToDefault(): int
    {
        $pdo = DB::conn();
        $groupId = self::ensureDefaultGroup();

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO routing_user_group_members (group_id, user_id)
                 SELECT ?, id FROM users
                 ON DUPLICATE KEY UPDATE group_id = VALUES(group_id)'
            );
            $insert->execute([$groupId]);
            $countStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM routing_user_group_members WHERE group_id = ?'
            );
            $countStmt->execute([$groupId]);
            $count = (int) $countStmt->fetchColumn();
            $pdo->commit();
            return $count;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
