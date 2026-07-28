<?php

class Csrf {
    public static function token(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }

    public static function validate(?string $token): bool {
        $expected = $_SESSION['csrf_token'] ?? '';
        return is_string($token)
            && $token !== ''
            && is_string($expected)
            && $expected !== ''
            && hash_equals($expected, $token);
    }
}
