<?php

class EmailTwoFactorAuth {
    private const TTL_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;
    private const RESEND_DELAY_SECONDS = 60;
    private const MAX_SENDS_PER_CHALLENGE = 5;
    private const MAX_NEW_CHALLENGES_PER_ACCOUNT_WINDOW = 5;
    private const MAX_NEW_CHALLENGES_PER_IP_WINDOW = 25;
    private const CHALLENGE_WINDOW_MINUTES = 15;

    public static function begin(array $user): void {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || !filter_var($user['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid user email is required for two-factor authentication');
        }

        $pdo = DB::conn();
        self::cleanup($pdo);
        $ipHash = hash('sha256', LoginRateLimiter::clientIp());
        $limit = $pdo->prepare(
            'SELECT
               SUM(CASE WHEN user_id = ? THEN 1 ELSE 0 END) AS account_count,
               SUM(CASE WHEN request_ip_hash = ? THEN 1 ELSE 0 END) AS ip_count
             FROM email_login_challenges
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );
        $limit->execute([$userId, $ipHash, self::CHALLENGE_WINDOW_MINUTES]);
        $counts = $limit->fetch() ?: [];
        if (
            (int) ($counts['account_count'] ?? 0) >= self::MAX_NEW_CHALLENGES_PER_ACCOUNT_WINDOW
            || (int) ($counts['ip_count'] ?? 0) >= self::MAX_NEW_CHALLENGES_PER_IP_WINDOW
        ) {
            throw new RuntimeException('Too many verification codes requested. Try again in 15 minutes.');
        }

        $code = self::newCode();
        $binding = bin2hex(random_bytes(32));
        $bindingHash = hash('sha256', $binding);
        $codeHash = password_hash($code, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO email_login_challenges
                 (user_id, session_hash, request_ip_hash, code_hash, expires_at, max_attempts, send_count, last_sent_at)
                 VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?, 1, NOW())'
            );
            $insert->execute([
                $userId,
                $bindingHash,
                $ipHash,
                $codeHash,
                self::TTL_MINUTES,
                self::MAX_ATTEMPTS,
            ]);
            $challengeId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        try {
            EmailTwoFactorMailer::sendCode(EmailTwoFactorSettings::runtime(), $user, $code, self::TTL_MINUTES);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE email_login_challenges SET consumed_at = NOW() WHERE id = ?')->execute([$challengeId]);
            throw $e;
        }

        $_SESSION['pending_email_2fa'] = [
            'challenge_id' => $challengeId,
            'binding' => $binding,
            'masked_email' => self::maskEmail((string) $user['email']),
        ];
    }

    public static function pending(): ?array {
        $pending = $_SESSION['pending_email_2fa'] ?? null;
        if (!is_array($pending) || empty($pending['challenge_id']) || empty($pending['binding'])) {
            return null;
        }

        $pdo = DB::conn();
        $stmt = $pdo->prepare(
            'SELECT id, expires_at, last_sent_at, send_count
             FROM email_login_challenges
             WHERE id = ? AND session_hash = ? AND consumed_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([
            (int) $pending['challenge_id'],
            hash('sha256', (string) $pending['binding']),
        ]);
        $challenge = $stmt->fetch();
        if (!$challenge) {
            self::cancel();
            return null;
        }

        return [
            'challenge_id' => (int) $challenge['id'],
            'masked_email' => (string) ($pending['masked_email'] ?? ''),
            'resend_after' => self::resendAfter((string) $challenge['last_sent_at']),
            'send_count' => (int) $challenge['send_count'],
        ];
    }

    public static function verify(string $code): array {
        $pending = $_SESSION['pending_email_2fa'] ?? null;
        if (!is_array($pending) || empty($pending['challenge_id']) || empty($pending['binding'])) {
            return ['success' => false, 'reason' => 'missing'];
        }
        if (!preg_match('/^\d{6}$/', $code)) {
            return ['success' => false, 'reason' => 'invalid'];
        }

        $pdo = DB::conn();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM email_login_challenges WHERE id = ? FOR UPDATE');
            $stmt->execute([(int) $pending['challenge_id']]);
            $challenge = $stmt->fetch();
            if (
                !$challenge
                || $challenge['consumed_at'] !== null
                || !hash_equals((string) $challenge['session_hash'], hash('sha256', (string) $pending['binding']))
            ) {
                $pdo->rollBack();
                self::cancel();
                return ['success' => false, 'reason' => 'missing'];
            }

            if (strtotime((string) $challenge['expires_at']) <= time()) {
                $pdo->prepare('UPDATE email_login_challenges SET consumed_at = NOW() WHERE id = ?')
                    ->execute([$challenge['id']]);
                $pdo->commit();
                self::cancel();
                return ['success' => false, 'reason' => 'expired'];
            }

            $attempts = (int) $challenge['attempts'] + 1;
            $valid = password_verify($code, (string) $challenge['code_hash']);
            if (!$valid) {
                $consume = $attempts >= (int) $challenge['max_attempts'];
                $update = $pdo->prepare(
                    'UPDATE email_login_challenges
                     SET attempts = ?, consumed_at = CASE WHEN ? = 1 THEN NOW() ELSE consumed_at END
                     WHERE id = ?'
                );
                $update->execute([$attempts, $consume ? 1 : 0, $challenge['id']]);
                $pdo->commit();
                if ($consume) {
                    self::cancel();
                    return ['success' => false, 'reason' => 'attempts_exhausted'];
                }
                return [
                    'success' => false,
                    'reason' => 'invalid',
                    'attempts_left' => max(0, (int) $challenge['max_attempts'] - $attempts),
                ];
            }

            $userStmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $userStmt->execute([(int) $challenge['user_id']]);
            $user = $userStmt->fetch();
            if (!$user || !Auth::canAccessSite($user)) {
                $pdo->prepare('UPDATE email_login_challenges SET consumed_at = NOW(), attempts = ? WHERE id = ?')
                    ->execute([$attempts, $challenge['id']]);
                $pdo->commit();
                self::cancel();
                return ['success' => false, 'reason' => 'access_disabled'];
            }

            $pdo->prepare('UPDATE email_login_challenges SET consumed_at = NOW(), attempts = ? WHERE id = ?')
                ->execute([$attempts, $challenge['id']]);
            $pdo->commit();
            self::cancel();
            return ['success' => true, 'user_id' => (int) $user['id']];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function resend(): array {
        $pending = $_SESSION['pending_email_2fa'] ?? null;
        if (!is_array($pending) || empty($pending['challenge_id']) || empty($pending['binding'])) {
            return ['success' => false, 'reason' => 'missing'];
        }

        $pdo = DB::conn();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT c.*, u.email, u.name, u.role, u.status
                 FROM email_login_challenges c
                 JOIN users u ON u.id = c.user_id
                 WHERE c.id = ? FOR UPDATE'
            );
            $stmt->execute([(int) $pending['challenge_id']]);
            $challenge = $stmt->fetch();
            if (
                !$challenge
                || $challenge['consumed_at'] !== null
                || !hash_equals((string) $challenge['session_hash'], hash('sha256', (string) $pending['binding']))
                || !Auth::canAccessSite($challenge)
            ) {
                $pdo->rollBack();
                self::cancel();
                return ['success' => false, 'reason' => 'missing'];
            }

            $retryAfter = self::resendAfter((string) $challenge['last_sent_at']);
            if ($retryAfter > 0) {
                $pdo->rollBack();
                return ['success' => false, 'reason' => 'rate_limited', 'retry_after' => $retryAfter];
            }
            if ((int) $challenge['send_count'] >= self::MAX_SENDS_PER_CHALLENGE) {
                $pdo->prepare('UPDATE email_login_challenges SET consumed_at = NOW() WHERE id = ?')
                    ->execute([$challenge['id']]);
                $pdo->commit();
                self::cancel();
                return ['success' => false, 'reason' => 'send_limit'];
            }

            $code = self::newCode();
            $update = $pdo->prepare(
                'UPDATE email_login_challenges
                 SET code_hash = ?, attempts = 0, send_count = send_count + 1,
                     last_sent_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                 WHERE id = ?'
            );
            $update->execute([password_hash($code, PASSWORD_DEFAULT), self::TTL_MINUTES, $challenge['id']]);
            $pdo->commit();

            try {
                EmailTwoFactorMailer::sendCode(
                    EmailTwoFactorSettings::runtime(),
                    $challenge,
                    $code,
                    self::TTL_MINUTES
                );
            } catch (Throwable $e) {
                $pdo->prepare('UPDATE email_login_challenges SET consumed_at = NOW() WHERE id = ?')
                    ->execute([$challenge['id']]);
                self::cancel();
                throw $e;
            }

            return ['success' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function cancel(): void {
        unset($_SESSION['pending_email_2fa']);
    }

    private static function cleanup(PDO $pdo): void {
        if (random_int(1, 20) !== 1) {
            return;
        }
        $pdo->exec(
            'DELETE FROM email_login_challenges
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)'
        );
    }

    private static function resendAfter(string $lastSentAt): int {
        $elapsed = time() - strtotime($lastSentAt);
        return max(0, self::RESEND_DELAY_SECONDS - $elapsed);
    }

    private static function newCode(): string {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function maskEmail(string $email): string {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return '***';
        }
        $visible = substr($local, 0, min(2, strlen($local)));
        return $visible . str_repeat('*', max(3, strlen($local) - strlen($visible))) . '@' . $domain;
    }
}
