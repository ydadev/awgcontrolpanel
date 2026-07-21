<?php

class RoutingConfigBuilder
{
    public static function buildForServer(int $serverId): array
    {
        $pdo = DB::conn();
        $serverStmt = $pdo->prepare('SELECT id, name, host FROM vpn_servers WHERE id = ? LIMIT 1');
        $serverStmt->execute([$serverId]);
        $server = $serverStmt->fetch();
        if (!$server) {
            throw new InvalidArgumentException('Server not found');
        }

        $ingressStmt = $pdo->prepare('
            SELECT ri.*, pool.canonical_cidr AS pool_cidr
            FROM routing_ingresses ri
            LEFT JOIN routing_ip_pools pool ON pool.id = ri.ip_pool_id
            WHERE ri.server_id = ? AND ri.enabled = 1
            ORDER BY ri.id
        ');
        $ingressStmt->execute([$serverId]);

        $linkStmt = $pdo->prepare('
            SELECT l.*
            FROM routing_server_links l
            JOIN routing_ingresses i ON i.id = l.ingress_id
            WHERE (i.server_id = ? OR l.egress_server_id = ?) AND l.enabled = 1
            ORDER BY l.id
        ');
        $linkStmt->execute([$serverId, $serverId]);

        $config = [
            'server_id' => $serverId,
            'format_version' => 1,
            'ingresses' => $ingressStmt->fetchAll(),
            'links' => $linkStmt->fetchAll(),
            'policies' => RoutingCompiler::compileServerPolicies($serverId),
        ];
        $config['configuration_hash'] = hash('sha256', json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $config['created_at'] = gmdate('c');

        return $config;
    }
}
