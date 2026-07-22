<?php

class LoginRateLimiter {
    private const WINDOW_SECONDS = 3600;
    private const LOCK_SECONDS = 3600;
    private const ACCOUNT_IP_LIMIT = 3;
    private const IP_LIMIT = 20;

    public static function clientIp(): string {
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        return $ip !== '' ? $ip : 'unknown';
    }

    public static function isBlocked(string $email, ?string $ip = null): bool {
        return self::secondsUntilAllowed($email, $ip) > 0;
    }

    public static function secondsUntilAllowed(string $email, ?string $ip = null): int {
        $keys = self::keys($email, $ip);
        $pdo = DB::conn();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(TIMESTAMPDIFF(SECOND, NOW(), locked_until)), 0)
             FROM login_rate_limits
             WHERE (scope = ? AND identifier_hash = ?) OR (scope = ? AND identifier_hash = ?)'
        );
        $stmt->execute([
            'account_ip', $keys['account_ip'],
            'ip', $keys['ip'],
        ]);

        return max(0, (int)$stmt->fetchColumn());
    }

    public static function recordFailure(string $email, ?string $ip = null): void {
        self::cleanupExpiredRows();
        $keys = self::keys($email, $ip);
        self::incrementBucket('account_ip', $keys['account_ip'], self::ACCOUNT_IP_LIMIT);
        self::incrementBucket('ip', $keys['ip'], self::IP_LIMIT);
    }

    public static function clearSuccessfulLogin(string $email, ?string $ip = null): void {
        $keys = self::keys($email, $ip);
        $stmt = DB::conn()->prepare(
            "DELETE FROM login_rate_limits WHERE scope = 'account_ip' AND identifier_hash = ?"
        );
        $stmt->execute([$keys['account_ip']]);
    }

    private static function keys(string $email, ?string $ip): array {
        $normalizedEmail = strtolower(trim($email));
        $normalizedIp = trim((string)($ip ?? self::clientIp()));
        if ($normalizedIp === '') {
            $normalizedIp = 'unknown';
        }

        return [
            'account_ip' => hash('sha256', $normalizedEmail . "\0" . $normalizedIp),
            'ip' => hash('sha256', $normalizedIp),
        ];
    }

    private static function incrementBucket(string $scope, string $hash, int $limit): void {
        $pdo = DB::conn();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO login_rate_limits
                    (scope, identifier_hash, failed_attempts, window_started_at, locked_until)
                 VALUES (?, ?, 0, NOW(), NULL)
                 ON DUPLICATE KEY UPDATE identifier_hash = VALUES(identifier_hash)'
            );
            $stmt->execute([$scope, $hash]);

            $stmt = $pdo->prepare(
                'SELECT failed_attempts, window_started_at, locked_until
                 FROM login_rate_limits
                 WHERE scope = ? AND identifier_hash = ?
                 FOR UPDATE'
            );
            $stmt->execute([$scope, $hash]);
            $row = $stmt->fetch();

            $windowExpired = !$row || strtotime((string)$row['window_started_at']) <= time() - self::WINDOW_SECONDS;
            $locked = !empty($row['locked_until']) && strtotime((string)$row['locked_until']) > time();

            if ($locked) {
                $pdo->commit();
                return;
            }

            $attempts = $windowExpired ? 1 : ((int)$row['failed_attempts'] + 1);
            $lockedUntil = $attempts >= $limit
                ? date('Y-m-d H:i:s', time() + self::LOCK_SECONDS)
                : null;

            $stmt = $pdo->prepare(
                'UPDATE login_rate_limits
                 SET failed_attempts = ?,
                     window_started_at = CASE WHEN ? = 1 THEN NOW() ELSE window_started_at END,
                     locked_until = ?
                 WHERE scope = ? AND identifier_hash = ?'
            );
            $stmt->execute([$attempts, $windowExpired ? 1 : 0, $lockedUntil, $scope, $hash]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function cleanupExpiredRows(): void {
        DB::conn()->exec(
            'DELETE FROM login_rate_limits
             WHERE updated_at < NOW() - INTERVAL 2 DAY
             LIMIT 500'
        );
    }
}
