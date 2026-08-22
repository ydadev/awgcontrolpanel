<?php

final class SecretStore
{
    private const PREFIX = 'awgsec:v1:';
    private const DEFAULT_KEY_ID = 'primary';

    public static function encrypt(string $plaintext, string $purpose): string
    {
        if ($plaintext === '') {
            return '';
        }

        $purpose = self::validatePurpose($purpose);
        $keyId = self::activeKeyId();
        $key = self::purposeKey(self::currentKeyMaterial(), $purpose);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return self::PREFIX . $keyId . ':' . self::base64UrlEncode($nonce . $ciphertext);
    }

    public static function decrypt(string $stored, string $purpose): string
    {
        if ($stored === '' || !str_starts_with($stored, 'awgsec:')) {
            return $stored;
        }

        $purpose = self::validatePurpose($purpose);
        if (!preg_match('/^awgsec:v1:([A-Za-z0-9_-]{1,32}):([A-Za-z0-9_-]+)$/', $stored, $matches)) {
            throw new RuntimeException('Stored secret has an unsupported or malformed envelope');
        }

        $keyId = $matches[1];
        $keys = self::keyring();
        if (!isset($keys[$keyId])) {
            throw new RuntimeException("No decryption key is configured for key id: {$keyId}");
        }

        $payload = self::base64UrlDecode($matches[2]);
        if (strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Stored secret payload is invalid');
        }

        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(
            $ciphertext,
            $nonce,
            self::purposeKey($keys[$keyId], $purpose)
        );
        if ($plaintext === false) {
            throw new RuntimeException('Unable to authenticate or decrypt the stored secret');
        }

        return $plaintext;
    }

    public static function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    public static function needsRewrap(string $stored): bool
    {
        if ($stored === '') {
            return false;
        }
        if (!self::isEncrypted($stored)) {
            return true;
        }

        return !str_starts_with($stored, self::PREFIX . self::activeKeyId() . ':');
    }

    public static function rewrap(string $stored, string $purpose): string
    {
        if ($stored === '') {
            return '';
        }
        $plaintext = self::decrypt($stored, $purpose);
        if (!self::needsRewrap($stored)) {
            return $stored;
        }

        return self::encrypt($plaintext, $purpose);
    }

    public static function assertReady(): void
    {
        self::activeKeyId();
        self::currentKeyMaterial();
    }

    private static function activeKeyId(): string
    {
        $keyId = trim((string) Config::get('SECRET_STORE_ACTIVE_KEY_ID', self::DEFAULT_KEY_ID));
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $keyId)) {
            throw new RuntimeException('SECRET_STORE_ACTIVE_KEY_ID must contain only letters, digits, underscore or dash');
        }

        return $keyId;
    }

    /** @return array<string,string> */
    private static function keyring(): array
    {
        $keys = [self::activeKeyId() => self::currentKeyMaterial()];
        $previous = trim((string) Config::get('SECRET_STORE_PREVIOUS_KEYS', ''));
        if ($previous === '') {
            return $keys;
        }

        foreach (explode(',', $previous) as $entry) {
            $parts = explode('=', trim($entry), 2);
            if (count($parts) !== 2 || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $parts[0])) {
                throw new RuntimeException('SECRET_STORE_PREVIOUS_KEYS contains an invalid key entry');
            }
            if (isset($keys[$parts[0]])) {
                throw new RuntimeException("Duplicate secret-store key id: {$parts[0]}");
            }
            $keys[$parts[0]] = self::normalizeKeyMaterial($parts[1], 'previous secret-store key');
        }

        return $keys;
    }

    private static function currentKeyMaterial(): string
    {
        $configured = (string) Config::get(
            'SECRET_STORE_KEY',
            Config::get('SETTINGS_ENCRYPTION_KEY', '')
        );

        return self::normalizeKeyMaterial($configured, 'SECRET_STORE_KEY or SETTINGS_ENCRYPTION_KEY');
    }

    private static function normalizeKeyMaterial(string $configured, string $label): string
    {
        $configured = trim($configured);
        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, strlen('base64:')), true);
            if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new RuntimeException("{$label} base64 value must decode to exactly 32 bytes");
            }
            return $decoded;
        }

        if (strlen($configured) < 32 || str_starts_with($configured, 'replace-with-')) {
            throw new RuntimeException("{$label} must contain at least 32 random characters");
        }

        return sodium_crypto_generichash(
            'awgcontrolpanel:secret-store:master:v1:' . $configured,
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        );
    }

    private static function purposeKey(string $masterKey, string $purpose): string
    {
        return sodium_crypto_generichash(
            'awgcontrolpanel:secret-store:purpose:v1:' . $purpose,
            $masterKey,
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        );
    }

    private static function validatePurpose(string $purpose): string
    {
        $purpose = trim($purpose);
        if ($purpose === '' || strlen($purpose) > 128) {
            throw new InvalidArgumentException('Secret purpose must contain between 1 and 128 characters');
        }

        return $purpose;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Stored secret payload is not valid base64url');
        }

        return $decoded;
    }
}
