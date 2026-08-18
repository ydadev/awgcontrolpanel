<?php

require_once __DIR__ . '/../inc/VpnClient.php';

$dnsServers = '10.10.11.192, 8.8.8.8, 77.88.8.8';
$protocols = ['wireguard-standard', 'awg2'];

foreach ($protocols as $protocol) {
    $config = VpnClient::buildClientConfig(
        'private-key',
        '10.8.2.10',
        'server-public-key',
        'preshared-key',
        '192.0.2.10',
        51820,
        [],
        $protocol,
        $dnsServers
    );

    if (strpos($config, 'DNS = ' . $dnsServers) === false) {
        fwrite(STDERR, "Custom DNS is missing for {$protocol}\n");
        exit(1);
    }
}

echo "vpn_client_dns_test: ok\n";
