<?php

class RoutingPolicyResolver
{
    public static function resolveForClient(int $clientId): array
    {
        $stmt = DB::conn()->prepare('SELECT server_id, client_ip FROM vpn_clients WHERE id = ? LIMIT 1');
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();
        if (!$client) {
            throw new InvalidArgumentException('Connection not found');
        }

        return RoutingCompiler::compileServerPolicies((int) $client['server_id']);
    }
}
