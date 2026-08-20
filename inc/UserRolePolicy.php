<?php

final class UserRolePolicy {
    public const ADMIN = 'admin';
    public const MODERATOR = 'moderator';
    public const USER = 'user';

    public static function roles(): array {
        return [self::ADMIN, self::MODERATOR, self::USER];
    }

    public static function isKnownRole(string $role): bool {
        return in_array($role, self::roles(), true);
    }

    public static function canManageUsers(string $role): bool {
        return in_array($role, [self::ADMIN, self::MODERATOR], true);
    }

    public static function canManageUser(string $actorRole, string $targetRole, bool $isSelf = false): bool {
        if ($actorRole === self::ADMIN) {
            return true;
        }

        return $actorRole === self::MODERATOR
            && $targetRole === self::USER
            && !$isSelf;
    }

    public static function canAssignRole(string $actorRole, string $targetRole): bool {
        return $actorRole === self::ADMIN && self::isKnownRole($targetRole);
    }

    public static function hasAdministrativeSiteAccess(string $role): bool {
        return in_array($role, [self::ADMIN, self::MODERATOR], true);
    }
}
