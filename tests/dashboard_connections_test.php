<?php

require_once __DIR__ . '/../inc/VpnClient.php';

function failDashboardConnectionsTest(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$scopeMethod = new ReflectionMethod(VpnClient::class, 'dashboardVisibilityScope');
$scopeMethod->setAccessible(true);

[$adminSql, $adminParams] = $scopeMethod->invoke(null, 'admin', 1);
if (trim($adminSql) !== '1 = 1' || $adminParams !== []) {
    failDashboardConnectionsTest('Admin dashboard scope is restricted unexpectedly');
}

[$moderatorSql, $moderatorParams] = $scopeMethod->invoke(null, 'moderator', 17);
foreach (['u.role = "user"', 'managed_access.can_view = 1', 'managed_access.can_create_clients = 1', 'own_access.can_view = 1'] as $required) {
    if (!str_contains($moderatorSql, $required)) {
        failDashboardConnectionsTest('Moderator dashboard scope omits: ' . $required);
    }
}
if ($moderatorParams !== [17, 17, 17]) {
    failDashboardConnectionsTest('Moderator dashboard scope has unexpected parameters');
}

[$userSql, $userParams] = $scopeMethod->invoke(null, 'user', 23);
if (!str_contains($userSql, 'c.user_id = ?') || !str_contains($userSql, 'own_access.can_view = 1')) {
    failDashboardConnectionsTest('User dashboard scope does not enforce ownership and server access');
}
if ($userParams !== [23, 23]) {
    failDashboardConnectionsTest('User dashboard scope has unexpected parameters');
}

$clientSource = file_get_contents(__DIR__ . '/../inc/VpnClient.php');
$routeSource = file_get_contents(__DIR__ . '/../public/index.php');
$template = file_get_contents(__DIR__ . '/../templates/dashboard.twig');
if (!is_string($clientSource) || !is_string($routeSource) || !is_string($template)) {
    failDashboardConnectionsTest('Dashboard sources could not be read');
}
if (!str_contains($clientSource, 'LIMIT \' . $effectivePerPage . \' OFFSET \' . $offset')) {
    failDashboardConnectionsTest('Dashboard query does not enforce SQL pagination');
}
if (!str_contains($clientSource, 'SELECT c.id, c.server_id, c.user_id, c.protocol_id')) {
    failDashboardConnectionsTest('Dashboard query does not use an explicit field projection');
}
foreach (['$connectionPerPage', '$connectionSort', '$connectionDirection'] as $required) {
    if (!str_contains($routeSource, $required)) {
        failDashboardConnectionsTest('Dashboard route omits: ' . $required);
    }
}
foreach (['$sortColumns', "['20', '50', 'all']", 'array_key_exists($sort, $sortColumns)'] as $required) {
    if (!str_contains($clientSource, $required)) {
        failDashboardConnectionsTest('Dashboard query validation omits: ' . $required);
    }
}
foreach (['name="connections_search"', 'connections_page=', 'connections.items', "sortable_header('Сервер'", "sortable_header('Срок действия'", "sortable_header('Лимит трафика'", "sortable_header('Скорость'", "'all': 'Все'"] as $required) {
    if (!str_contains($template, $required)) {
        failDashboardConnectionsTest('Dashboard template omits: ' . $required);
    }
}
if (str_contains($template, "t('dashboard.recent_servers')")) {
    failDashboardConnectionsTest('Recent servers block is still rendered');
}

echo "Dashboard connections tests passed\n";
