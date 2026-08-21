<?php

require_once __DIR__ . '/../inc/UserRolePolicy.php';

$expectations = [
    [UserRolePolicy::canManageUsers('admin'), true, 'admin must manage users'],
    [UserRolePolicy::canManageUsers('moderator'), true, 'moderator must manage users'],
    [UserRolePolicy::canManageUsers('user'), false, 'regular user must not manage users'],
    [UserRolePolicy::canManageUser('moderator', 'user'), true, 'moderator must manage regular users'],
    [UserRolePolicy::canManageUser('moderator', 'moderator'), false, 'moderator must not manage moderators'],
    [UserRolePolicy::canManageUser('moderator', 'admin'), false, 'moderator must not manage administrators'],
    [UserRolePolicy::canManageUser('moderator', 'user', true), false, 'moderator must not manage self'],
    [UserRolePolicy::canManageUser('admin', 'admin'), true, 'administrator must manage accounts'],
    [UserRolePolicy::canAssignRole('admin', 'moderator'), true, 'administrator must assign moderator role'],
    [UserRolePolicy::canAssignRole('moderator', 'admin'), false, 'moderator must not assign roles'],
    [UserRolePolicy::canProvisionConnectionFor('moderator', 'user', false), true, 'moderator must provision regular users'],
    [UserRolePolicy::canProvisionConnectionFor('moderator', 'moderator', true), true, 'moderator must provision self'],
    [UserRolePolicy::canProvisionConnectionFor('moderator', 'moderator', false), false, 'moderator must not provision other moderators'],
    [UserRolePolicy::canProvisionConnectionFor('moderator', 'admin', false), false, 'moderator must not provision administrators'],
];

foreach ($expectations as [$actual, $expected, $message]) {
    if ($actual !== $expected) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$router = file_get_contents(__DIR__ . '/../public/index.php');
$controller = file_get_contents(__DIR__ . '/../controllers/SettingsController.php');
$layout = file_get_contents(__DIR__ . '/../templates/layout.twig');
$serverView = file_get_contents(__DIR__ . '/../templates/servers/view.twig');
$migration = file_get_contents(__DIR__ . '/../migrations/091_add_moderator_role.sql');

foreach ([$router, $controller, $layout, $serverView, $migration] as $source) {
    if (!is_string($source) || $source === '') {
        fwrite(STDERR, "Cannot read moderator permission sources\n");
        exit(1);
    }
}

$managedRoutes = [
    "Router::get('/users'",
    "Router::post('/users/{id}/password'",
    "Router::post('/users/add'",
    "Router::post('/users/{id}/delete'",
    "Router::post('/users/{id}/server-access'",
    "Router::post('/users/{id}/site-access'",
];
foreach ($managedRoutes as $route) {
    $position = strpos($router, $route);
    $guard = $position === false ? false : strpos(substr($router, $position, 240), 'requireUserManager();');
    if ($position === false || $guard === false) {
        fwrite(STDERR, "Moderator user-management route guard is missing: {$route}\n");
        exit(1);
    }
}

if (!preg_match("/Router::post\('\/users\/\{id\}\/role'.*?requireAdmin\(\)/s", $router)
    || !preg_match("/Router::get\('\/settings'.*?requireSettingsPageAccess\(\)/s", $router)
    || strpos($layout, "user.role in ['admin', 'moderator']") === false
    || strpos($controller, 'Auth::canManageUser($target, $user)') === false) {
    fwrite(STDERR, "Moderator hierarchy enforcement is incomplete\n");
    exit(1);
}

if (substr_count($serverView, '{% if can_manage_server %}') < 3
    || strpos($router, "Router::post('/servers/{id}/config/import'") === false
    || !preg_match("/Router::post\('\/servers\/\{id\}\/config\/import'.*?requireAdmin\(\)/s", $router)
    || strpos($migration, "ENUM('admin', 'moderator', 'user')") === false) {
    fwrite(STDERR, "Server configuration or database role protection is incomplete\n");
    exit(1);
}

echo "moderator_role_permissions_test: ok\n";
