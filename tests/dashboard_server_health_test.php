<?php

require_once __DIR__ . '/../vendor/autoload.php';

function failDashboardServerHealthTest(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$monitoring = file_get_contents(__DIR__ . '/../inc/ServerMonitoring.php');
$router = file_get_contents(__DIR__ . '/../public/index.php');
$template = file_get_contents(__DIR__ . '/../templates/dashboard.twig');
if (!is_string($monitoring) || !is_string($router) || !is_string($template)) {
    failDashboardServerHealthTest('Dashboard server health sources could not be read');
}

foreach (['getServerChartMetrics', 'getLatestServerMetrics', 'AVG(cpu_percent)', 'AVG(network_rx_mbps)', '$maxPoints = max(24, min(240, $maxPoints))', '$maxPoints - 2', 'age_seconds'] as $required) {
    if (!str_contains($monitoring, $required)) {
        failDashboardServerHealthTest('Server metrics aggregation omits: ' . $required);
    }
}

$routeStart = strpos($router, "Router::get('/api/servers/{id}/metrics'");
$routeBlock = $routeStart === false ? '' : substr($router, $routeStart, 2200);
foreach (['userCanViewServer($user, $serverId)', 'getServerChartMetrics', 'getLatestServerMetrics', "'latest' => \$latest"] as $required) {
    if (!str_contains($routeBlock, $required)) {
        failDashboardServerHealthTest('Server metrics route omits: ' . $required);
    }
}

foreach (['Здоровье серверов', 'data-dashboard-server-health', 'dashboardHealthCpu-', 'dashboardHealthRam-', 'dashboardHealthNetwork-', 'data-health-disk-detail', 'hours=24&max_points=96', 'updateDashboardServerHealthAll'] as $required) {
    if (!str_contains($template, $required)) {
        failDashboardServerHealthTest('Dashboard health UI omits: ' . $required);
    }
}
$healthPosition = strpos($template, 'id="serverHealthTitle"');
$createPosition = strpos($template, 'id="dashboardCreateConnectionForm"');
if ($healthPosition === false || $createPosition === false || $healthPosition >= $createPosition) {
    failDashboardServerHealthTest('Server health block is not placed before connection creation');
}

$twig = new Twig\Environment(new Twig\Loader\FilesystemLoader(__DIR__ . '/../templates'));
$twig->addFunction(new Twig\TwigFunction('t', static fn (string $key, array $params = []) => $key));
$twig->addFunction(new Twig\TwigFunction('getFlag', static fn (string $code) => $code));
$source = new Twig\Source($template, 'dashboard.twig');
$twig->parse($twig->tokenize($source));

echo "dashboard_server_health_test: ok\n";
