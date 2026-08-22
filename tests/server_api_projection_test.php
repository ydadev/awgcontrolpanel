<?php

require_once __DIR__ . '/../inc/ServerApiProjection.php';

function projectionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$server = [
    'id' => 7,
    'user_id' => 3,
    'user_email' => 'owner@example.test',
    'name' => 'Test node',
    'host' => 'vpn.example.test',
    'port' => 22,
    'username' => 'operator',
    'password' => 'ssh-password',
    'ssh_key' => 'private-key',
    'server_public_key' => 'public-key',
    'preshared_key' => 'vpn-secret',
    'awg_params' => '{"secret":"value"}',
    'install_options' => '{"password":"value"}',
    'container_name' => 'vpn-container',
    'vpn_port' => 51820,
    'vpn_subnet' => '10.0.0.0/24',
    'dns_servers' => '1.1.1.1',
    'status' => 'active',
    'error_message' => null,
    'can_create_clients' => 1,
    'protocols' => [
        ['id' => 2, 'slug' => 'wireguard-standard', 'name' => 'WireGuard', 'install_script' => 'secret script'],
    ],
];

$regular = ServerApiProjection::one($server, false);
$admin = ServerApiProjection::one($server, true);
$sensitiveFields = [
    'password',
    'ssh_key',
    'server_public_key',
    'preshared_key',
    'awg_params',
    'install_options',
];

foreach ($sensitiveFields as $field) {
    projectionAssert(!array_key_exists($field, $regular), "Regular projection exposed $field");
    projectionAssert(!array_key_exists($field, $admin), "Admin projection exposed $field");
}

projectionAssert(!array_key_exists('username', $regular), 'Regular projection exposed SSH username');
projectionAssert(array_key_exists('username', $admin), 'Admin metadata is missing SSH username');
projectionAssert(($regular['protocols'][0] ?? []) === [
    'id' => 2,
    'slug' => 'wireguard-standard',
    'name' => 'WireGuard',
], 'Protocol projection is not allowlisted');
projectionAssert(count(ServerApiProjection::collection([$server], false)) === 1, 'Collection projection failed');

$indexSource = file_get_contents(__DIR__ . '/../public/index.php');
projectionAssert($indexSource !== false, 'Unable to read API routes');
projectionAssert(
    strpos($indexSource, '$isAdmin = (($user[\'role\'] ?? \'\') === \'admin\');') !== false,
    'The server-list API must define the role flag before using it'
);
projectionAssert(
    strpos($indexSource, 'ServerApiProjection::collection($servers, $isAdmin)') !== false,
    'The server-list API must apply the allowlist projection'
);

echo "Server API projection tests passed\n";
