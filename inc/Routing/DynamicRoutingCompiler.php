<?php

class DynamicRoutingCompiler
{
    private const MAX_DOMAINS_PER_PATH = 5000;
    private const MAX_CIDRS_PER_PATH = 10000;
    private const MAX_SET_ELEMENTS = 65536;

    private const DYNAMIC_PROTECTED_CIDRS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    public static function parseDomainEntries(string $input): array
    {
        $entries = [];
        foreach (self::parseRuleParts($input) as $part) {
            $canonical = self::normalizeDomainPattern($part);
            $entries[$canonical] = $canonical;
        }

        if (count($entries) > self::MAX_DOMAINS_PER_PATH) {
            throw new InvalidArgumentException(
                'В одном направлении допускается не более ' . self::MAX_DOMAINS_PER_PATH . ' доменных правил'
            );
        }

        return array_values($entries);
    }

    public static function normalizeDomainPattern(string $pattern): string
    {
        $pattern = strtolower(rtrim(trim($pattern), '.'));
        if ($pattern === '') {
            throw new InvalidArgumentException('Пустой доменный шаблон');
        }
        if ($pattern === '*') {
            return '*';
        }
        if (strlen($pattern) > 253 || preg_match('/[^a-z0-9._*-]/', $pattern)) {
            throw new InvalidArgumentException('Недопустимый доменный шаблон: ' . $pattern);
        }

        $labels = explode('.', $pattern);
        foreach ($labels as $index => $label) {
            if ($label === '' || strlen($label) > 63) {
                throw new InvalidArgumentException('Недопустимый доменный шаблон: ' . $pattern);
            }
            if ($label === '*') {
                if ($index !== 0 || count($labels) < 2) {
                    throw new InvalidArgumentException('Символ * разрешен только как первый отдельный уровень домена');
                }
                continue;
            }
            if (str_contains($label, '*') || !preg_match('/^[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?$/', $label)) {
                throw new InvalidArgumentException('Недопустимый доменный шаблон: ' . $pattern);
            }
        }

        return $pattern;
    }

    public static function parseCidrEntries(string $input): array
    {
        $entries = [];
        foreach (self::parseRuleParts($input) as $part) {
            $normalized = RoutingValidator::normalizeIpv4Cidr($part);
            $entries[$normalized['canonical_cidr']] = $normalized['canonical_cidr'];
        }

        if (count($entries) > self::MAX_CIDRS_PER_PATH) {
            throw new InvalidArgumentException(
                'В одном направлении допускается не более ' . self::MAX_CIDRS_PER_PATH . ' IPv4/CIDR правил'
            );
        }

        return array_values($entries);
    }

    public static function parseDnsUpstreams(string $input): array
    {
        $entries = [];
        foreach (preg_split('/[\s,]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) as $part) {
            $host = $part;
            $port = null;
            if (str_contains($part, '#')) {
                [$host, $portValue] = explode('#', $part, 2);
                $port = filter_var($portValue, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1, 'max_range' => 65535],
                ]);
                if ($port === false) {
                    throw new InvalidArgumentException('Недопустимый порт DNS: ' . $part);
                }
            }
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new InvalidArgumentException('DNS upstream должен быть IPv4-адресом: ' . $part);
            }
            $canonical = $host . ($port !== null ? '#' . $port : '');
            $entries[$canonical] = $canonical;
        }

        if (!$entries) {
            throw new InvalidArgumentException('Укажите хотя бы один DNS upstream');
        }
        if (count($entries) > 8) {
            throw new InvalidArgumentException('Допускается не более восьми DNS upstream');
        }

        return array_values($entries);
    }

    public static function compileNft(array $module): string
    {
        $paths = self::enabledPaths($module['paths'] ?? []);
        $sources = array_values(array_unique(array_merge(
            $module['source_cidrs'] ?? [],
            ['172.17.0.0/16']
        )));
        if (!$sources) {
            throw new InvalidArgumentException('Не найдены исходные VPN-пулы');
        }

        $timeout = max(300, min(604800, (int) ($module['set_timeout_seconds'] ?? 21600)));
        $lines = [
            'table inet awg_policy {',
            '    set sources4 {',
            '        type ipv4_addr',
            '        flags interval',
            '        auto-merge',
            '        elements = { ' . implode(', ', $sources) . ' }',
            '    }',
        ];

        foreach ($paths as $path) {
            $id = (int) $path['id'];
            $cidrs = self::rulesOfType($path, 'cidr');
            $lines[] = '';
            $lines[] = '    set p' . $id . '_static4 {';
            $lines[] = '        type ipv4_addr';
            $lines[] = '        flags interval';
            $lines[] = '        auto-merge';
            if ($cidrs) {
                $lines[] = '        elements = { ' . implode(', ', $cidrs) . ' }';
            }
            $lines[] = '    }';
            $lines[] = '';
            $lines[] = '    set p' . $id . '_dynamic4 {';
            $lines[] = '        type ipv4_addr';
            $lines[] = '        flags interval, timeout';
            $lines[] = '        timeout ' . $timeout . 's';
            $lines[] = '        size ' . self::MAX_SET_ELEMENTS;
            $lines[] = '    }';
        }

        $hostInterfaces = array_values(array_unique(array_filter(
            $module['host_ingress_interfaces'] ?? [],
            [self::class, 'isSafeInterfaceName']
        )));
        $lines[] = '';
        $lines[] = '    chain dns_redirect {';
        $lines[] = '        type nat hook prerouting priority -105; policy accept;';
        if (!empty($module['intercept_dns'])) {
            foreach ($hostInterfaces as $interface) {
                $quoted = self::nftQuote($interface);
                $lines[] = '        iifname ' . $quoted . ' udp dport 53 redirect to :53';
                $lines[] = '        iifname ' . $quoted . ' tcp dport 53 redirect to :53';
            }
        }
        $lines[] = '    }';

        $lines[] = '';
        $lines[] = '    chain prerouting {';
        $lines[] = '        type filter hook prerouting priority mangle; policy accept;';
        $lines[] = '        meta mark != 0 return';
        foreach ($paths as $path) {
            $mark = self::formatMark((int) $path['fwmark']);
            $lines[] = '        ip saddr @sources4 ip daddr @p' . (int) $path['id']
                . '_static4 counter meta mark set ' . $mark . ' return';
        }
        $lines[] = '        ip daddr { ' . implode(', ', self::DYNAMIC_PROTECTED_CIDRS) . ' } return';
        foreach ($paths as $path) {
            $mark = self::formatMark((int) $path['fwmark']);
            $lines[] = '        ip saddr @sources4 ip daddr @p' . (int) $path['id']
                . '_dynamic4 counter meta mark set ' . $mark . ' return';
        }
        $lines[] = '    }';

        $lines[] = '';
        $lines[] = '    chain postrouting {';
        $lines[] = '        type nat hook postrouting priority srcnat; policy accept;';
        foreach ($paths as $path) {
            $lines[] = '        meta mark ' . self::formatMark((int) $path['fwmark'])
                . ' oifname ' . self::nftQuote($path['interface_name']) . ' masquerade';
        }
        $lines[] = '    }';

        $mssPaths = array_values(array_filter($paths, static fn(array $path): bool => !empty($path['tcp_mss'])));
        $lines[] = '';
        $lines[] = '    chain forward_mss {';
        $lines[] = '        type filter hook forward priority mangle; policy accept;';
        foreach ($mssPaths as $path) {
            $mss = max(536, min(8960, (int) $path['tcp_mss']));
            $lines[] = '        meta mark ' . self::formatMark((int) $path['fwmark'])
                . ' tcp flags syn tcp option maxseg size set ' . $mss;
        }
        $lines[] = '    }';
        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    public static function compileDnsmasq(array $module, string $backend = 'nftset'): string
    {
        if (!in_array($backend, ['nftset', 'ipset'], true)) {
            throw new InvalidArgumentException('Unsupported dnsmasq set backend');
        }
        $upstreams = self::parseDnsUpstreams((string) ($module['dns_upstreams'] ?? ''));
        $cacheSize = max(0, min(100000, (int) ($module['dns_cache_size'] ?? 10000)));
        $timeout = max(300, min(604800, (int) ($module['set_timeout_seconds'] ?? 21600)));
        $maxCacheTtl = max(60, min(3600, intdiv($timeout, 2)));

        $lines = [
            '# Managed by AWG Control Panel dynamic routing module.',
            'no-resolv',
            'domain-needed',
            'filter-AAAA',
            'cache-size=' . $cacheSize,
            'max-cache-ttl=' . $maxCacheTtl,
        ];
        foreach ($upstreams as $upstream) {
            $lines[] = 'server=' . $upstream;
        }

        foreach (self::enabledPaths($module['paths'] ?? []) as $path) {
            foreach (self::rulesOfType($path, 'domain') as $domain) {
                $dnsmasqPattern = $domain === '*' ? '#' : $domain;
                if ($backend === 'nftset') {
                    $lines[] = 'nftset=/' . $dnsmasqPattern . '/4#inet#awg_policy#p'
                        . (int) $path['id'] . '_dynamic4';
                } else {
                    $lines[] = 'ipset=/' . $dnsmasqPattern . '/' . self::ipsetName((int) $path['id']);
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }

    public static function configurationHash(array $module): string
    {
        $normalized = [
            'server_id' => (int) ($module['server_id'] ?? 0),
            'enabled' => !empty($module['enabled']),
            'intercept_dns' => !empty($module['intercept_dns']),
            'dns_upstreams' => self::parseDnsUpstreams((string) ($module['dns_upstreams'] ?? '')),
            'dns_cache_size' => (int) ($module['dns_cache_size'] ?? 10000),
            'set_timeout_seconds' => (int) ($module['set_timeout_seconds'] ?? 21600),
            'source_cidrs' => array_values($module['source_cidrs'] ?? []),
            'paths' => [],
        ];
        foreach (self::enabledPaths($module['paths'] ?? []) as $path) {
            $normalized['paths'][] = [
                'id' => (int) $path['id'],
                'interface_name' => $path['interface_name'],
                'routing_table_id' => (int) $path['routing_table_id'],
                'fwmark' => (int) $path['fwmark'],
                'priority' => (int) $path['priority'],
                'tcp_mss' => $path['tcp_mss'] !== null ? (int) $path['tcp_mss'] : null,
                'cidrs' => self::rulesOfType($path, 'cidr'),
                'domains' => self::rulesOfType($path, 'domain'),
            ];
        }

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function isSafeInterfaceName(string $interface): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_.-]{1,32}$/', $interface);
    }

    public static function ipsetName(int $pathId): string
    {
        if ($pathId <= 0) {
            throw new InvalidArgumentException('Invalid routing path id');
        }
        return 'awg_p' . $pathId . '_dynamic4';
    }

    public static function dynamicProtectedCidrs(): array
    {
        return self::DYNAMIC_PROTECTED_CIDRS;
    }

    private static function parseRuleParts(string $input): array
    {
        $parts = [];
        foreach (preg_split('/\R/', $input) as $line) {
            $line = trim((string) $line);
            if ($line === '' || preg_match('/^(?:#|--|\/\/)/', $line)) {
                continue;
            }
            $line = trim((string) preg_replace('/\s+(?:#|--|\/\/).*$/', '', $line));
            if ($line === '') {
                continue;
            }
            foreach (explode(',', $line) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $parts[] = $part;
                }
            }
        }
        return $parts;
    }

    private static function enabledPaths(array $paths): array
    {
        $paths = array_values(array_filter($paths, static fn(array $path): bool => !empty($path['enabled'])));
        usort($paths, static function (array $left, array $right): int {
            return [(int) $left['priority'], (int) $left['id']]
                <=> [(int) $right['priority'], (int) $right['id']];
        });
        foreach ($paths as $path) {
            if (!self::isSafeInterfaceName((string) ($path['interface_name'] ?? ''))) {
                throw new InvalidArgumentException('Недопустимое имя интерфейса маршрута');
            }
        }
        return $paths;
    }

    private static function rulesOfType(array $path, string $type): array
    {
        $key = $type === 'domain' ? 'domains' : 'cidrs';
        return array_values(array_unique(array_filter(array_map(
            'strval',
            $path[$key] ?? []
        ))));
    }

    private static function nftQuote(string $value): string
    {
        return '"' . addcslashes($value, "\\\"") . '"';
    }

    private static function formatMark(int $mark): string
    {
        return sprintf('0x%x', $mark);
    }
}
