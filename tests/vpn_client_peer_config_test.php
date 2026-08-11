<?php

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

echo "WireGuard peer configuration tests passed\n";
