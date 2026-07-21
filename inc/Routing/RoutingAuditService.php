<?php

class RoutingAuditService
{
    public static function log(?int $actorUserId, string $action, ?string $subjectType = null, ?int $subjectId = null, ?int $serverId = null, $before = null, $after = null): void
    {
        try {
            $pdo = DB::conn();
            $stmt = $pdo->prepare('
                INSERT INTO routing_audit_log
                (actor_user_id, action, subject_type, subject_id, server_id, before_json, after_json, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $actorUserId,
                $action,
                $subjectType,
                $subjectId,
                $serverId,
                $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            error_log('Routing audit failed: ' . $e->getMessage());
        }
    }
}
