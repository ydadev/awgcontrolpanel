<?php

require_once __DIR__ . '/../inc/ClientAllowedIpsPolicy.php';

function failAllowedIpsTest(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function ipv4MatchesCidr(string $address, string $cidr): bool
{
    [$network, $prefixText] = explode('/', $cidr, 2);
    $prefix = (int) $prefixText;
    $addressValue = unpack('N', inet_pton($address))[1];
    $networkValue = unpack('N', inet_pton($network))[1];
    $size = 1 << (32 - $prefix);
    return intdiv($addressValue, $size) === intdiv($networkValue, $size);
}

$allowedIps = ClientAllowedIpsPolicy::allowedIps(ClientAllowedIpsPolicy::MODE_LOCAL_BYPASS);
$entries = array_map('trim', explode(',', $allowedIps));
$ipv4Entries = array_values(array_filter($entries, static fn(string $entry): bool => strpos($entry, ':') === false));

if (count($ipv4Entries) !== 273) {
    failAllowedIpsTest('Unexpected IPv4 prefix count: ' . count($ipv4Entries));
}

foreach (['1.1.1.1', '8.8.8.8', '77.88.8.8'] as $address) {
    $matched = false;
    foreach ($ipv4Entries as $cidr) {
        if (ipv4MatchesCidr($address, $cidr)) {
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        failAllowedIpsTest('Expected tunneled address is absent: ' . $address);
    }
}

foreach ([
    '10.10.10.2', '100.64.0.1', '169.254.1.1', '172.16.0.1', '192.168.1.1',
    '224.0.0.251', '91.206.92.82', '185.211.103.32', '194.87.69.189',
    '195.133.67.55', '176.32.37.19', '185.211.103.180',
] as $address) {
    foreach ($ipv4Entries as $cidr) {
        if (ipv4MatchesCidr($address, $cidr)) {
            failAllowedIpsTest('Excluded address is still tunneled: ' . $address . ' via ' . $cidr);
        }
    }
}

foreach (['fc00::/7', 'fe80::/10', 'ff00::/8'] as $excludedIpv6) {
    if (in_array($excludedIpv6, $entries, true)) {
        failAllowedIpsTest('Excluded IPv6 range is present: ' . $excludedIpv6);
    }
}

if (ClientAllowedIpsPolicy::supports(0, 'wireguard-standard')) {
    failAllowedIpsTest('Invalid server id was accepted');
}

$canonical = ClientAllowedIpsPolicy::canonicalizeAllowedIpsText("# test\n1.1.1.9/24 -- normalized\n8.8.8.8/32\n// ignored");
if ($canonical !== '1.1.1.0/24, 8.8.8.8/32') {
    failAllowedIpsTest('Editable AllowedIPs text was not canonicalized: ' . $canonical);
}

echo "Client AllowedIPs policy tests passed\n";
