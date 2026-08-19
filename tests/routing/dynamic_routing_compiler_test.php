<?php

require_once __DIR__ . '/../../inc/Routing/RoutingValidator.php';
require_once __DIR__ . '/../../inc/Routing/DynamicRoutingCompiler.php';

class VpnServer
{
    public string $lastCommand = '';

    public function executeCommand(string $command, bool $sudo = false): string
    {
        $this->lastCommand = $command;
        return $command;
    }
}

require_once __DIR__ . '/../../inc/Routing/DynamicRoutingModuleService.php';

$domains = DynamicRoutingCompiler::parseDomainEntries(
    "*.YouTube.com.\napi.youtube.com\n*.com\n*.youtube.com # duplicate"
);
if ($domains !== ['*.youtube.com', 'api.youtube.com', '*.com']) {
    fwrite(STDERR, 'Unexpected normalized domains: ' . json_encode($domains) . PHP_EOL);
    exit(1);
}

foreach (['', 'foo.*.example.com', '*example.com', 'foo..com'] as $invalid) {
    $rejected = false;
    try {
        DynamicRoutingCompiler::normalizeDomainPattern($invalid);
    } catch (InvalidArgumentException $e) {
        $rejected = true;
    }
    if (!$rejected) {
        fwrite(STDERR, 'Invalid domain pattern was accepted: ' . $invalid . PHP_EOL);
        exit(1);
    }
}

$module = [
    'server_id' => 2,
    'enabled' => 1,
    'intercept_dns' => 1,
    'dns_upstreams' => '192.0.2.53, 8.8.8.8#53',
    'dns_cache_size' => 10000,
    'set_timeout_seconds' => 21600,
    'source_cidrs' => ['10.8.1.0/24', '10.8.2.0/23'],
    'ingress_interfaces' => ['wg0', 'awg0'],
    'host_ingress_interfaces' => ['wg0', 'awg0'],
    'paths' => [
        [
            'id' => 11,
            'interface_name' => 'office1',
            'routing_table_id' => 211,
            'fwmark' => 0x440b,
            'priority' => 50,
            'tcp_mss' => null,
            'enabled' => 1,
            'domains' => [],
            'cidrs' => ['192.0.2.0/24'],
        ],
        [
            'id' => 12,
            'interface_name' => 'awg-egress',
            'routing_table_id' => 212,
            'fwmark' => 0x440c,
            'priority' => 100,
            'tcp_mss' => 1200,
            'enabled' => 1,
            'domains' => $domains,
            'cidrs' => ['0.0.0.0/7', '1.0.0.0/9'],
        ],
    ],
];

$nft = DynamicRoutingCompiler::compileNft($module);
$requiredNft = [
    'table inet awg_policy',
    'auto-merge',
    'set p11_static4',
    'set p12_dynamic4',
    'meta mark set 0x440b',
    'meta mark set 0x440c',
    'tcp option maxseg size set 1200',
    'iifname "wg0" udp dport 53 redirect to :53',
];
foreach ($requiredNft as $needle) {
    if (!str_contains($nft, $needle)) {
        fwrite(STDERR, 'Generated nftables config omits: ' . $needle . PHP_EOL);
        exit(1);
    }
}

$staticOffice = strpos($nft, 'ip daddr @p11_static4');
$protected = strpos($nft, 'ip daddr { 0.0.0.0/8');
$dynamic = strpos($nft, 'ip daddr @p12_dynamic4');
if ($staticOffice === false || $protected === false || $dynamic === false
    || !($staticOffice < $protected && $protected < $dynamic)) {
    fwrite(STDERR, 'Static/private/dynamic rule ordering is unsafe' . PHP_EOL);
    exit(1);
}

$dnsmasq = DynamicRoutingCompiler::compileDnsmasq($module);
foreach ([
    'server=192.0.2.53',
    'server=8.8.8.8#53',
    'nftset=/*.youtube.com/4#inet#awg_policy#p12_dynamic4',
    'nftset=/*.com/4#inet#awg_policy#p12_dynamic4',
] as $needle) {
    if (!str_contains($dnsmasq, $needle)) {
        fwrite(STDERR, 'Generated dnsmasq config omits: ' . $needle . PHP_EOL);
        exit(1);
    }
}

$dnsmasqIpSet = DynamicRoutingCompiler::compileDnsmasq($module, 'ipset');
foreach ([
    'ipset=/*.youtube.com/awg_p12_dynamic4',
    'ipset=/*.com/awg_p12_dynamic4',
] as $needle) {
    if (!str_contains($dnsmasqIpSet, $needle)) {
        fwrite(STDERR, 'Generated dnsmasq ipset config omits: ' . $needle . PHP_EOL);
        exit(1);
    }
}

$refreshMethod = new ReflectionMethod(DynamicRoutingModuleService::class, 'compileRefreshScript');
$refreshMethod->setAccessible(true);
$refresh = $refreshMethod->invoke(null, $module);
$path = tempnam(sys_get_temp_dir(), 'dynamic-routing-refresh-');
file_put_contents($path, $refresh);
exec('sh -n ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
unlink($path);
if ($exitCode !== 0) {
    fwrite(STDERR, 'Generated refresh script is invalid: ' . implode("\n", $output) . PHP_EOL);
    exit(1);
}
foreach ([
    'ipset create awg_policy_sources',
    'ipset create \'awg_p12_dynamic4\'',
    'AWG_POLICY_DYNAMIC',
    '--set-xmark 0x440c/0xffffffff',
] as $needle) {
    if (!str_contains($refresh, $needle)) {
        fwrite(STDERR, 'Generated refresh script omits ipset fallback: ' . $needle . PHP_EOL);
        exit(1);
    }
}

$module['paths'][0]['peer_config_path'] = '/etc/wireguard/office1.conf';
$module['paths'][0]['legacy_route_file_path'] = '/opt/awgcontrolpanel/routes/office1.txt';
$module['paths'][1]['peer_config_path'] = '/opt/amnezia/awg-egress/awg-egress.conf';
$module['paths'][1]['legacy_route_file_path'] = '/opt/amnezia/awg-egress/routes.txt';
$applyMethod = new ReflectionMethod(DynamicRoutingModuleService::class, 'applyEnabled');
$applyMethod->setAccessible(true);
$server = new VpnServer();
$applyCommand = $applyMethod->invoke(null, $server, $module);
$path = tempnam(sys_get_temp_dir(), 'dynamic-routing-apply-');
file_put_contents($path, $applyCommand);
exec('sh -n ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
unlink($path);
if ($exitCode !== 0) {
    fwrite(STDERR, 'Generated apply command is invalid: ' . implode("\n", $output) . PHP_EOL);
    exit(1);
}
if (!str_contains($server->lastCommand, '/path-12.cidrs')
    || !str_contains($server->lastCommand, 'while IFS= read -r cidr')) {
    fwrite(STDERR, 'Generated apply command does not stream CIDRs from a path file' . PHP_EOL);
    exit(1);
}
foreach (['vpn_show_peers', 'vpn_set_allowed', "command -v awg"] as $needle) {
    if (!str_contains($server->lastCommand, $needle)) {
        fwrite(STDERR, 'Generated apply command omits VPN controller fallback: ' . $needle . PHP_EOL);
        exit(1);
    }
}
foreach (['dnsmasq-nftset.conf', 'dnsmasq-ipset.conf', 'dns_backend=ipset'] as $needle) {
    if (!str_contains($server->lastCommand, $needle)) {
        fwrite(STDERR, 'Generated apply command omits dnsmasq backend selection: ' . $needle . PHP_EOL);
        exit(1);
    }
}

$largeModule = $module;
$largeModule['paths'][1]['cidrs'] = [];
for ($thirdOctet = 0; $thirdOctet < 256; $thirdOctet++) {
    for ($fourthOctet = 0; $fourthOctet < 4; $fourthOctet++) {
        $largeModule['paths'][1]['cidrs'][] = "100.{$thirdOctet}.{$fourthOctet}.0/24";
    }
}
$largeServer = new VpnServer();
$applyMethod->invoke(null, $largeServer, $largeModule);
if (strlen($largeServer->lastCommand) >= 131072) {
    fwrite(STDERR, 'Generated apply command exceeds the safe SSH command size' . PHP_EOL);
    exit(1);
}

$firstHash = DynamicRoutingCompiler::configurationHash($module);
$module['paths'][1]['domains'][] = 'instagram.com';
$secondHash = DynamicRoutingCompiler::configurationHash($module);
if (hash_equals($firstHash, $secondHash)) {
    fwrite(STDERR, 'Configuration hash ignores domain changes' . PHP_EOL);
    exit(1);
}

echo "Dynamic routing compiler tests passed\n";
