<?php

class RoutingValidator
{
    private const PROTECTED_CIDRS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '255.255.255.255/32',
    ];

    public static function normalizeIpv4Cidr(string $cidr): array
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            throw new InvalidArgumentException('CIDR is required');
        }

        if (strpos($cidr, ':') !== false) {
            throw new InvalidArgumentException('IPv6 routing is not supported in the first version');
        }

        if (strpos($cidr, '/') === false) {
            $cidr .= '/32';
        }

        [$ip, $prefix] = explode('/', $cidr, 2);
        $prefix = filter_var($prefix, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 32],
        ]);

        if ($prefix === false || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new InvalidArgumentException('Invalid IPv4 CIDR');
        }

        $ipLong = self::ipToUnsigned($ip);
        $mask = $prefix === 0 ? 0 : ((0xffffffff << (32 - $prefix)) & 0xffffffff);
        $networkLong = $ipLong & $mask;
        $network = long2ip((int) $networkLong);

        return [
            'family' => 4,
            'network' => inet_pton($network),
            'network_ip' => $network,
            'prefix_length' => (int) $prefix,
            'canonical_cidr' => $network . '/' . (int) $prefix,
        ];
    }

    public static function validateUserDestination(string $cidr, bool $allowDefaultRoute = false, int $minimumPrefixLength = 8): array
    {
        $normalized = self::normalizeIpv4Cidr($cidr);

        if (!$allowDefaultRoute && $normalized['prefix_length'] === 0) {
            throw new InvalidArgumentException('Default route is not allowed for this user');
        }

        if ($normalized['prefix_length'] < $minimumPrefixLength) {
            throw new InvalidArgumentException('CIDR prefix is too broad for this permission');
        }

        foreach (self::PROTECTED_CIDRS as $protected) {
            if (self::cidrOverlaps($normalized['canonical_cidr'], $protected)) {
                throw new InvalidArgumentException('CIDR overlaps a protected network');
            }
        }

        return $normalized;
    }

    public static function cidrOverlaps(string $left, string $right): bool
    {
        $a = self::normalizeIpv4Cidr($left);
        $b = self::normalizeIpv4Cidr($right);

        $aStart = self::ipToUnsigned($a['network_ip']);
        $aEnd = $aStart + (2 ** (32 - $a['prefix_length'])) - 1;
        $bStart = self::ipToUnsigned($b['network_ip']);
        $bEnd = $bStart + (2 ** (32 - $b['prefix_length'])) - 1;

        return $aStart <= $bEnd && $bStart <= $aEnd;
    }

    private static function ipToUnsigned(string $ip): int
    {
        return (int) sprintf('%u', ip2long($ip));
    }
}
