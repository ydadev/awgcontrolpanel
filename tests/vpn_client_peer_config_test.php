<?php

class VpnServer
{
    public function executeCommand(string $command, bool $sudo = false): string
    {
        return "public_key=runtime-public-key\nlisten_port=51835\n";
    }
}

require_once __DIR__ . '/../inc/VpnClient.php';

$config = <<<'CONF'
[Interface]
Address = 10.8.1.1/24
PrivateKey = server-private-key

[Peer]
PublicKey = stale-same-ip
AllowedIPs = 10.8.1.2/32

[Peer]
PublicKey = same-public-key
AllowedIPs = 10.8.1.9/32

[Peer]
PublicKey = retained-peer
AllowedIPs = 10.8.1.3/32
CONF;

$method = new ReflectionMethod(VpnClient::class, 'removeConflictingPeersFromConfig');
$method->setAccessible(true);
$filtered = $method->invoke(null, $config, 'same-public-key', '10.8.1.2');

foreach (['stale-same-ip', 'same-public-key'] as $removedKey) {
    if (str_contains($filtered, $removedKey)) {
        fwrite(STDERR, "Conflicting peer {$removedKey} was retained\n");
        exit(1);
    }
}
if (!str_contains($filtered, 'retained-peer') || !str_contains($filtered, '[Interface]')) {
    fwrite(STDERR, "Non-conflicting WireGuard configuration was removed\n");
    exit(1);
}

$runtimeMethod = new ReflectionMethod(VpnClient::class, 'applyProtocolServerData');
$runtimeMethod->setAccessible(true);
$serverData = $runtimeMethod->invoke(
    null,
    new VpnServer(),
    ['container_name' => '', 'vpn_port' => 51836, 'server_public_key' => 'stale-public-key'],
    null,
    'wireguard-standard'
);
if ($serverData['vpn_port'] !== 51835 || $serverData['server_public_key'] !== 'runtime-public-key') {
    fwrite(STDERR, "Native WireGuard runtime data did not override shared server values\n");
    exit(1);
}

$clientConfig = VpnClient::buildClientConfig(
    'client-private-key',
    '10.8.1.2',
    'server-public-key',
    'preshared-key',
    '203.0.113.10',
    51835,
    [],
    'wireguard-standard',
    '10.10.11.192, 8.8.8.8, 77.88.8.8'
);
foreach ([
    'DNS = 10.10.11.192, 8.8.8.8, 77.88.8.8',
    'MTU = 1420',
    'AllowedIPs = 0.0.0.0/0, ::/0',
    'Endpoint = 203.0.113.10:51835',
] as $expectedLine) {
    if (!str_contains($clientConfig, $expectedLine)) {
        fwrite(STDERR, "Native WireGuard config omits {$expectedLine}\n");
        exit(1);
    }
}

$mtuMethod = new ReflectionMethod(VpnClient::class, 'applyClientMtuOverride');
$mtuMethod->setAccessible(true);
$directAwgConfig = $mtuMethod->invoke(null, $clientConfig, ['client_mtu' => 1280]);
if (!str_contains($directAwgConfig, 'MTU = 1280') || str_contains($directAwgConfig, 'MTU = 1420')) {
    fwrite(STDERR, "Per-protocol client MTU override was not applied\n");
    exit(1);
}

$allowedIpsMethod = new ReflectionMethod(VpnClient::class, 'applyClientAllowedIpsMode');
$allowedIpsMethod->setAccessible(true);
$splitConfig = $allowedIpsMethod->invoke(null, $clientConfig, ClientAllowedIpsPolicy::MODE_LOCAL_BYPASS, '194.87.69.189');
if (str_contains($splitConfig, 'AllowedIPs = 0.0.0.0/0, ::/0')) {
    fwrite(STDERR, "Local-bypass mode retained the full-tunnel AllowedIPs\n");
    exit(1);
}
foreach (['10.0.0.0/8', '192.168.0.0/16', '194.87.69.189/32'] as $excludedCidr) {
    if (str_contains($splitConfig, $excludedCidr)) {
        fwrite(STDERR, "Local-bypass config includes excluded CIDR {$excludedCidr}\n");
        exit(1);
    }
}
if (!str_contains($splitConfig, 'AllowedIPs = 0.0.0.0/5')) {
    fwrite(STDERR, "Local-bypass config did not receive the calculated AllowedIPs\n");
    exit(1);
}

$internalDnsConfig = preg_replace(
    '/^DNS\s*=.*$/mi',
    'DNS = 10.254.0.53',
    $clientConfig,
    1
);
$internalDnsSplitConfig = $allowedIpsMethod->invoke(
    null,
    $internalDnsConfig,
    ClientAllowedIpsPolicy::MODE_LOCAL_BYPASS,
    '203.0.113.10'
);
if (!str_contains($internalDnsSplitConfig, 'DNS = 10.254.0.53')) {
    fwrite(STDERR, "Internal DNS setting was changed while applying local-bypass mode\n");
    exit(1);
}
if (!str_contains($internalDnsSplitConfig, '10.254.0.53/32')) {
    fwrite(STDERR, "Internal DNS route was not appended to local-bypass config\n");
    exit(1);
}

$fullConfig = $allowedIpsMethod->invoke(null, $splitConfig, ClientAllowedIpsPolicy::MODE_FULL);
if (!str_contains($fullConfig, 'AllowedIPs = 0.0.0.0/0, ::/0')) {
    fwrite(STDERR, "Full-tunnel mode did not restore the default AllowedIPs\n");
    exit(1);
}

$quickConfig = <<<'CONF'
[Interface]
PrivateKey = server-private-key
Address = 10.255.44.2/30
ListenPort = 443
MTU = 1280
Table = off
Jc = 3
PostUp = /opt/amnezia/awg-egress/up.sh
PostDown = /opt/amnezia/awg-egress/down.sh

[Peer]
PublicKey = interserver-peer
AllowedIPs = 10.255.44.1/32

[Peer]
PublicKey = direct-client
AllowedIPs = 10.8.4.2/32
CONF;
$setconfMethod = new ReflectionMethod(VpnClient::class, 'buildRuntimeSetconf');
$setconfMethod->setAccessible(true);
$runtimeConfig = $setconfMethod->invoke(null, $quickConfig);
foreach (['Address =', 'MTU =', 'Table =', 'PostUp =', 'PostDown ='] as $forbiddenLine) {
    if (str_contains($runtimeConfig, $forbiddenLine)) {
        fwrite(STDERR, "Runtime setconf retained wg-quick directive {$forbiddenLine}\n");
        exit(1);
    }
}
foreach (['ListenPort = 443', 'Jc = 3', 'interserver-peer', 'direct-client'] as $requiredLine) {
    if (!str_contains($runtimeConfig, $requiredLine)) {
        fwrite(STDERR, "Runtime setconf omitted {$requiredLine}\n");
        exit(1);
    }
}

$preferredIpMethod = new ReflectionMethod(VpnClient::class, 'isPreferredClientIp');
$preferredIpMethod->setAccessible(true);
foreach (['10.8.2.255', '10.8.3.0'] as $skippedIp) {
    if ($preferredIpMethod->invoke(null, $skippedIp)) {
        fwrite(STDERR, "Wide-pool allocator accepted ambiguous host {$skippedIp}\n");
        exit(1);
    }
}
if (!$preferredIpMethod->invoke(null, '10.8.3.1')) {
    fwrite(STDERR, "Wide-pool allocator rejected valid host 10.8.3.1\n");
    exit(1);
}

$usedWidePoolIps = [
    '10.8.2.0' => true,
    '10.8.2.1' => true,
];
for ($host = 2; $host <= 254; $host++) {
    $usedWidePoolIps['10.8.2.' . $host] = true;
}
$findIpMethod = new ReflectionMethod(VpnClient::class, 'findAvailableClientIp');
$findIpMethod->setAccessible(true);
$nextWidePoolIp = $findIpMethod->invoke(null, ip2long('10.8.2.0'), 23, $usedWidePoolIps);
if ($nextWidePoolIp !== '10.8.3.1') {
    fwrite(STDERR, "Wide-pool allocator returned {$nextWidePoolIp} instead of 10.8.3.1\n");
    exit(1);
}

echo "WireGuard peer configuration tests passed\n";
