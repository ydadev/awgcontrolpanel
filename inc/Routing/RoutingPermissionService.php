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
}
