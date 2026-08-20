<?php

require_once __DIR__ . '/../inc/VpnClient.php';

$validNames = [
    'client',
    'Client_01',
    'home-router',
    'A1_b-2',
    str_repeat('a', 64),
];

foreach ($validNames as $name) {
    if (VpnClient::validateConnectionName($name) !== $name) {
        fwrite(STDERR, "Valid connection name was changed: {$name}\n");
        exit(1);
    }
}

$invalidNames = [
    '',
    '   ',
    ' client',
    'client ',
    '_client',
    '-client',
    'client name',
    'клиент',
    'client.name',
    'client/name',
    'client@name',
    str_repeat('a', 65),
];

foreach ($invalidNames as $name) {
    try {
        VpnClient::validateConnectionName($name);
        fwrite(STDERR, "Invalid connection name was accepted: {$name}\n");
        exit(1);
    } catch (InvalidArgumentException $e) {
        if ($e->getMessage() !== VpnClient::CONNECTION_NAME_ERROR) {
            fwrite(STDERR, "Unexpected validation message\n");
            exit(1);
        }
    }
}

$template = file_get_contents(__DIR__ . '/../templates/servers/view.twig');
$router = file_get_contents(__DIR__ . '/../public/index.php');
if (!is_string($template) || !is_string($router)) {
    fwrite(STDERR, "Cannot read connection form sources\n");
    exit(1);
}

if (preg_match('/<input[^>]+name="login"/i', $template)) {
    fwrite(STDERR, "Separate VPN login field is still present\n");
    exit(1);
}

if (strpos($template, 'pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,63}"') === false
    || strpos($template, 'clientNameError') === false) {
    fwrite(STDERR, "Client-side connection name validation is missing\n");
    exit(1);
}

if (substr_count($router, 'VpnClient::validateConnectionName(') < 2) {
    fwrite(STDERR, "Web and API server-side validation is missing\n");
    exit(1);
}

echo "connection_name_validation_test: ok\n";
