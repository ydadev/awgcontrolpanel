<?php

$serverSource = file_get_contents(__DIR__ . '/../inc/VpnServer.php');
$clientSource = file_get_contents(__DIR__ . '/../inc/VpnClient.php');

if ($serverSource === false || $clientSource === false) {
    fwrite(STDERR, "Unable to read SSH implementation files\n");
    exit(1);
}

$checks = [
    'VpnServer command timeout' => strpos($serverSource, 'timeout --signal=TERM --kill-after=5s 45s ssh') !== false,
    'VpnServer keepalive' => strpos($serverSource, '-o ServerAliveInterval=5 -o ServerAliveCountMax=2') !== false,
    'client creation preflight' => strpos($clientSource, 'if (!$server->testConnection())') !== false,
    'VpnClient command timeout' => strpos($clientSource, 'timeout --signal=TERM --kill-after=5s 45s sshpass') !== false,
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, $label . " is missing\n");
        exit(1);
    }
}

echo "ssh_timeout_test: ok\n";
