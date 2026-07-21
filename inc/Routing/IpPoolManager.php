<?php

class IpPoolManager
{
    public static function assertPoolAvailable(string $cidr): array
    {
        $normalized = RoutingValidator::normalizeIpv4Cidr($cidr);
        $stmt = DB::conn()->prepare('
            SELECT canonical_cidr
            FROM routing_ip_pools
            WHERE family = ?
        ');
        $stmt->execute([$normalized['family']]);
        foreach ($stmt->fetchAll() as $row) {
            if (RoutingValidator::cidrOverlaps($normalized['canonical_cidr'], $row['canonical_cidr'])) {
                throw new InvalidArgumentException('Routing pool overlaps existing pool ' . $row['canonical_cidr']);
            }
        }
        return $normalized;
    }
}
