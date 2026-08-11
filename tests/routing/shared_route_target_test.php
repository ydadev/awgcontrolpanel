<?php

require_once __DIR__ . '/../../inc/Routing/RoutingValidator.php';
require_once __DIR__ . '/../../inc/Routing/RoutingRouteTargetService.php';

class VpnServer
{
    public string $lastCommand = '';

    public function executeCommand(string $command, bool $sudo = false): string
    {
        $this->lastCommand = $command;
        return $command;
    }
}

$entries = RoutingRouteTargetService::parseEntries(
    "192.0.2.7/24\n203.0.113.8, 203.0.113.8/32 # duplicate\n# comment\n198.51.100.0/24"
);
$actual = array_column($entries, 'canonical_cidr');
$expected = ['192.0.2.0/24', '203.0.113.8/32', '198.51.100.0/24'];

if ($actual !== $expected) {
    fwrite(STDERR, 'Unexpected parsed routes: ' . json_encode($actual) . PHP_EOL);
    exit(1);
}

foreach (['', "::/0\n", "0.0.0.0/0\n"] as $invalid) {
    $rejected = false;
    try {
        RoutingRouteTargetService::parseEntries($invalid);
    } catch (InvalidArgumentException $e) {
        $rejected = true;
    }
    if (!$rejected) {
        fwrite(STDERR, "Invalid route list was accepted\n");
        exit(1);
    }
}

$server = new VpnServer();
$cidrs = ['192.0.2.0/24'];
$hash = hash('sha256', "192.0.2.0/24\n");
$sourceSubnets = ['172.17.0.0/16', '10.8.1.0/24', '10.8.2.0/24'];
$targets = [
    'applyLinuxRouteFile' => [
        'route_interface_name' => 'awg-egress',
        'route_file_path' => '/opt/amnezia/awg-egress/routes.txt',
    ],
    'applyWireGuardConfig' => [
        'route_interface_name' => 'office1',
        'route_file_path' => '/opt/awgcontrolpanel/routes/office1.txt',
    ],
];

foreach ($targets as $methodName => $target) {
    $method = new ReflectionMethod(RoutingRouteTargetService::class, $methodName);
    $method->setAccessible(true);
    $script = $method->invoke(null, $server, $target, $cidrs, $hash, $sourceSubnets);
    if (!str_contains($script, '__AWG_ROUTE_APPLY_OK__')) {
        fwrite(STDERR, "{$methodName} does not emit the success marker\n");
        exit(1);
    }
    foreach ($sourceSubnets as $sourceSubnet) {
        if (!str_contains($script, $sourceSubnet)) {
            fwrite(STDERR, "{$methodName} omits source subnet {$sourceSubnet}\n");
            exit(1);
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'route-script-');
    file_put_contents($path, $script);
    exec('sh -n ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
    unlink($path);
    if ($exitCode !== 0) {
        fwrite(STDERR, "{$methodName} generated invalid shell: " . implode("\n", $output) . PHP_EOL);
        exit(1);
    }
}

echo "Shared route target tests passed\n";
