<?php

require_once __DIR__ . '/../inc/WireGuardStats.php';

$standardCommand = WireGuardStats::buildDumpCommand('wireguard-standard', 'ignored-container');
if ($standardCommand !== 'wg show all dump 2>/dev/null') {
    fwrite(STDERR, "WireGuard Standard stats must be read from the host wg runtime\n");
    exit(1);
}

$awg2Command = WireGuardStats::buildDumpCommand('awg2', 'amnezia-awg2');
foreach (['docker exec -i', 'amnezia-awg2', 'command -v awg', 'show all dump'] as $fragment) {
    if (!str_contains($awg2Command, $fragment)) {
        fwrite(STDERR, "AWG2 stats command is missing {$fragment}\n");
        exit(1);
    }
}

$publicKey = 'client-public-key=';
$dump = implode("\n", [
    "wg0\tserver-private\tserver-public\t51835\toff",
    "wg0\tother-key=\t(none)\t198.51.100.2:1000\t10.8.1.2/32\t100\t200\t300\t25",
    "wg0\t{$publicKey}\tpsk=\t198.51.100.3:2000\t10.8.1.3/32\t1700000000\t123456\t654321\t25",
]);

$stats = WireGuardStats::parsePeerDump($dump, $publicKey);
if ($stats !== [
    'last_handshake' => 1700000000,
    'bytes_sent' => 123456,
    'bytes_received' => 654321,
]) {
    fwrite(STDERR, "WireGuard peer dump was parsed incorrectly\n");
    exit(1);
}

if (WireGuardStats::parsePeerDump($dump, 'missing-key=') !== null) {
    fwrite(STDERR, "Unknown WireGuard peer must not produce zero statistics\n");
    exit(1);
}

echo "wireguard_stats_test: ok\n";
