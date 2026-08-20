<?php

final class UserPasswordPolicy {
    public static function resolveForNewUser(string $submittedPassword, string $role, bool $siteAccess): string {
        $passwordIsRequired = $role === 'admin' || $siteAccess;
        if (!$passwordIsRequired) {
            return self::generateStrongPassword();
        }

        if ($submittedPassword === '') {
            throw new InvalidArgumentException('Password is required when site access is enabled');
        }
        if (strlen($submittedPassword) < 6) {
            throw new InvalidArgumentException('Password must be at least 6 characters');
        }

        return $submittedPassword;
    }

    private static function generateStrongPassword(): string {
        $randomPart = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        return 'A9!a' . $randomPart;
    }
}
