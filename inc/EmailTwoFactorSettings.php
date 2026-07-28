<?php

class EmailTwoFactorSettings {
    private const NAMESPACE = 'security';
    private const KEY = 'email_two_factor';

    public static function defaults(): array {
        return [
            'enabled' => false,
            'smtp_host' => '',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_username' => '',
            'smtp_password_encrypted' => '',
            'from_email' => '',
            'from_name' => Config::get('APP_NAME', 'AWG Control Panel'),
            'verified_fingerprint' => '',
            'verified_at' => '',
        ];
    }

    public static function get(): array {
        $settings = self::defaults();

        try {
            $pdo = DB::conn();
            $stmt = $pdo->prepare(
                'SELECT value FROM settings WHERE user_id IS NULL AND namespace = ? AND `key` = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([self::NAMESPACE, self::KEY]);
            $value = $stmt->fetchColumn();
            $stored = $value ? json_decode((string) $value, true) : null;
            if (is_array($stored)) {
                $settings = array_merge($settings, array_intersect_key($stored, $settings));
            }
        } catch (Throwable $e) {
            return $settings;
        }

        $settings['enabled'] = (bool) $settings['enabled'];
        $settings['smtp_port'] = (int) $settings['smtp_port'];
        return $settings;
    }

    public static function forForm(): array {
        $settings = self::get();
        $settings['has_password'] = $settings['smtp_password_encrypted'] !== '';
        unset($settings['smtp_password_encrypted'], $settings['verified_fingerprint']);
        return $settings;
    }

    public static function runtime(): array {
        $settings = self::get();
        $settings['smtp_password'] = self::decrypt((string) $settings['smtp_password_encrypted']);
        unset($settings['smtp_password_encrypted']);
        return $settings;
    }

    public static function isEnabled(): bool {
        return (bool) self::get()['enabled'];
    }

    public static function saveFromInput(array $input): array {
        $current = self::get();
        $next = self::buildFromInput($input, $current);

        if ($next['enabled']) {
            self::assertComplete($next);
            $fingerprint = self::fingerprint($next);
            if ($next['verified_fingerprint'] === '' || !hash_equals($next['verified_fingerprint'], $fingerprint)) {
                throw new RuntimeException('Test the SMTP settings successfully before enabling two-factor authentication');
            }
        }

        self::saveRaw($next);
        return $next;
    }

    public static function testAndSave(array $input, string $recipient): array {
        $current = self::get();
        $next = self::buildFromInput($input, $current);
        $next['enabled'] = (bool) $current['enabled'];
        self::assertComplete($next);

        $recipient = strtolower(trim($recipient));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a valid test recipient email address');
        }

        $runtime = $next;
        $runtime['smtp_password'] = self::decrypt((string) $next['smtp_password_encrypted']);
        EmailTwoFactorMailer::sendTest($runtime, $recipient);

        $next['verified_fingerprint'] = self::fingerprint($next);
        $next['verified_at'] = gmdate('Y-m-d H:i:s');
        self::saveRaw($next);
        return $next;
    }

    private static function buildFromInput(array $input, array $current): array {
        $password = (string) ($input['smtp_password'] ?? '');
        $encryption = strtolower(trim((string) ($input['smtp_encryption'] ?? 'ssl')));
        if (!in_array($encryption, ['ssl', 'tls'], true)) {
            throw new InvalidArgumentException('SMTP encryption must be SSL/TLS or STARTTLS');
        }

        $host = strtolower(trim((string) ($input['smtp_host'] ?? '')));
        if ($host !== '' && (!preg_match('/^[a-z0-9.-]+$/', $host) || str_contains($host, '..'))) {
            throw new InvalidArgumentException('Enter a valid SMTP host name');
        }

        $port = (int) ($input['smtp_port'] ?? 465);
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('SMTP port must be between 1 and 65535');
        }

        $username = trim((string) ($input['smtp_username'] ?? ''));
        $fromEmail = strtolower(trim((string) ($input['from_email'] ?? $username)));
        $fromName = trim(strip_tags((string) ($input['from_name'] ?? '')));
        if ($fromName === '') {
            $fromName = Config::get('APP_NAME', 'AWG Control Panel');
        }
        if (strlen($fromName) > 100) {
            $fromName = substr($fromName, 0, 100);
        }

        $next = [
            'enabled' => !empty($input['enabled']),
            'smtp_host' => $host,
            'smtp_port' => $port,
            'smtp_encryption' => $encryption,
            'smtp_username' => $username,
            'smtp_password_encrypted' => $password !== ''
                ? self::encrypt($password)
                : (string) ($current['smtp_password_encrypted'] ?? ''),
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'verified_fingerprint' => (string) ($current['verified_fingerprint'] ?? ''),
            'verified_at' => (string) ($current['verified_at'] ?? ''),
        ];

        if (self::fingerprint($next) !== self::fingerprint($current)) {
            $next['verified_fingerprint'] = '';
            $next['verified_at'] = '';
        }

        return $next;
    }

    private static function assertComplete(array $settings): void {
        if (
            trim((string) $settings['smtp_host']) === ''
            || trim((string) $settings['smtp_username']) === ''
            || trim((string) $settings['smtp_password_encrypted']) === ''
            || !filter_var($settings['from_email'], FILTER_VALIDATE_EMAIL)
        ) {
            throw new RuntimeException('SMTP host, username, password, and a valid sender email are required');
        }
    }

    private static function fingerprint(array $settings): string {
        $password = '';
        if (!empty($settings['smtp_password_encrypted'])) {
            $password = self::decrypt((string) $settings['smtp_password_encrypted']);
        }

        $material = json_encode([
            'host' => (string) ($settings['smtp_host'] ?? ''),
            'port' => (int) ($settings['smtp_port'] ?? 0),
            'encryption' => (string) ($settings['smtp_encryption'] ?? ''),
            'username' => (string) ($settings['smtp_username'] ?? ''),
            'password' => $password,
            'from_email' => (string) ($settings['from_email'] ?? ''),
            'from_name' => (string) ($settings['from_name'] ?? ''),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', (string) $material, self::encryptionKey());
    }

    private static function saveRaw(array $settings): void {
        $pdo = DB::conn();
        $json = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stmt = $pdo->prepare(
            'SELECT id FROM settings WHERE user_id IS NULL AND namespace = ? AND `key` = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([self::NAMESPACE, self::KEY]);
        $id = $stmt->fetchColumn();

        if ($id) {
            $update = $pdo->prepare('UPDATE settings SET value = CAST(? AS JSON), updated_at = NOW() WHERE id = ?');
            $update->execute([$json, $id]);
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO settings (user_id, namespace, `key`, value) VALUES (NULL, ?, ?, CAST(? AS JSON))'
        );
        $insert->execute([self::NAMESPACE, self::KEY, $json]);
    }

    private static function encrypt(string $plaintext): string {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, self::encryptionKey());
        return base64_encode($nonce . $ciphertext);
    }

    private static function decrypt(string $encoded): string {
        if ($encoded === '') {
            return '';
        }

        $payload = base64_decode($encoded, true);
        if ($payload === false || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Stored SMTP password is invalid');
        }

        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, self::encryptionKey());
        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt the stored SMTP password');
        }

        return $plaintext;
    }

    private static function encryptionKey(): string {
        $secret = (string) Config::get('SETTINGS_ENCRYPTION_KEY', Config::get('JWT_SECRET', ''));
        if (strlen($secret) < 32 || str_starts_with($secret, 'replace-with-')) {
            throw new RuntimeException('SETTINGS_ENCRYPTION_KEY or JWT_SECRET must contain at least 32 random characters');
        }

        return sodium_crypto_generichash(
            'awgcontrolpanel:email-two-factor:' . $secret,
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        );
    }
}
