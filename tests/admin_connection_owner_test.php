<?php

$source = file_get_contents(__DIR__ . '/../public/index.php');
if (!is_string($source) || $source === '') {
    fwrite(STDERR, "Cannot read public/index.php\n");
    exit(1);
}

$listStart = strpos($source, 'function listConnectionOwnerOptions');
$resolveStart = strpos($source, 'function resolveConnectionOwnerForCreateById');
$resolveEnd = strpos($source, 'function resolveConnectionOwnerForCreate(', $resolveStart);

if ($listStart === false || $resolveStart === false || $resolveEnd === false) {
    fwrite(STDERR, "Connection owner functions were not found\n");
    exit(1);
}

$listFunction = substr($source, $listStart, $resolveStart - $listStart);
$resolveFunction = substr($source, $resolveStart, $resolveEnd - $resolveStart);

foreach ([$listFunction, $resolveFunction] as $functionSource) {
    if (preg_match('/u\.status\s*=/i', $functionSource)) {
        fwrite(STDERR, "Site access must not limit admin connection provisioning\n");
        exit(1);
    }
}

if (strpos($resolveFunction, "has_server_access") === false) {
    fwrite(STDERR, "Server access validation is missing\n");
    exit(1);
}

if (strpos($resolveFunction, "can_create_clients") !== false) {
    fwrite(STDERR, "Create configs must not limit admin connection provisioning\n");
    exit(1);
}

echo "admin_connection_owner_test: ok\n";
