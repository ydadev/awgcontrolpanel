<?php

$index = file_get_contents(__DIR__ . '/../public/index.php');
$template = file_get_contents(__DIR__ . '/../templates/servers/view.twig');
$client = file_get_contents(__DIR__ . '/../inc/VpnClient.php');

if (!is_string($index) || !is_string($template) || !is_string($client)) {
    fwrite(STDERR, "Cannot read moderator connection sources\n");
    exit(1);
}

foreach ([
    "in_array(\$currentRole, ['admin', 'moderator'], true)",
    'UserRolePolicy::canProvisionConnectionFor(',
    "(\$user['role'] ?? '') === 'moderator' && \$canCreateClients",
    'listByServerAndProtocolForModerator(',
    'listByServerForModerator(',
] as $needle) {
    if (!str_contains($index, $needle) && !str_contains($client, $needle)) {
        fwrite(STDERR, 'Moderator connection flow omits: ' . $needle . PHP_EOL);
        exit(1);
    }
}

if (!str_contains($template, "user.role in ['admin', 'moderator']")) {
    fwrite(STDERR, "Moderator owner selector is missing\n");
    exit(1);
}

echo "moderator_connection_owner_test: ok\n";
