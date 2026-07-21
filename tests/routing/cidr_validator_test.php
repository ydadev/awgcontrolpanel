<?php

require_once __DIR__ . '/../../inc/Routing/RoutingValidator.php';

$cases = [
    ['2.48.1.10/13', '2.48.0.0/13'],
    ['8.8.8.8', '8.8.8.8/32'],
    ['1.1.1.9/24', '1.1.1.0/24'],
];

foreach ($cases as [$input, $expected]) {
    $actual = RoutingValidator::normalizeIpv4Cidr($input)['canonical_cidr'];
    if ($actual !== $expected) {
        fwrite(STDERR, "Expected {$expected}, got {$actual}\n");
        exit(1);
    }
}

$rejected = false;
try {
    RoutingValidator::normalizeIpv4Cidr('::/0');
} catch (InvalidArgumentException $e) {
    $rejected = true;
}
if (!$rejected) {
    fwrite(STDERR, "IPv6 was not rejected\n");
    exit(1);
}

$rejected = false;
try {
    RoutingValidator::validateUserDestination('192.168.1.0/24');
} catch (InvalidArgumentException $e) {
    $rejected = true;
}
if (!$rejected) {
    fwrite(STDERR, "Protected network was not rejected\n");
    exit(1);
}

echo "CIDR validator tests passed\n";
