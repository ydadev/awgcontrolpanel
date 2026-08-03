<?php

class RoutingCompiler
{
    public static function compileServerPolicies(int $serverId): array
    {
        $stmt = DB::conn()->prepare(
            'SELECT
                target.id AS route_target_id,
                target.target_key,
                target.name,
                target.egress_name,
                target.transport_label,
                target.route_interface_name,
                target.apply_strategy,
                target.server_link_id,
                target.priority,
                routing_group.id AS group_id,
                routing_group.name AS group_name,
                entry.canonical_cidr AS destination_cidr
             FROM routing_route_targets target
             JOIN routing_user_groups routing_group ON routing_group.id = target.group_id
             JOIN routing_ingresses ingress ON ingress.id = target.ingress_id
             JOIN routing_ip_list_entries entry ON entry.ip_list_id = target.ip_list_id
             WHERE ingress.server_id = ?
               AND target.enabled = 1
               AND routing_group.enabled = 1
             ORDER BY target.priority, target.id, entry.id'
        );
        $stmt->execute([$serverId]);

        $policies = [];
        foreach ($stmt->fetchAll() as $row) {
            $targetId = (int) $row['route_target_id'];
            if (!isset($policies[$targetId])) {
                $policies[$targetId] = [
                    'policy_id' => $row['target_key'],
                    'route_target_id' => $targetId,
                    'name' => $row['name'],
                    'group_id' => (int) $row['group_id'],
                    'group_name' => $row['group_name'],
                    'egress_name' => $row['egress_name'],
                    'transport_label' => $row['transport_label'],
                    'route_interface_name' => $row['route_interface_name'],
                    'apply_strategy' => $row['apply_strategy'],
                    'server_link_id' => $row['server_link_id'] !== null
                        ? (int) $row['server_link_id']
                        : null,
                    'priority' => (int) $row['priority'],
                    'rules' => [],
                ];
            }
            $policies[$targetId]['rules'][] = [
                'destination_cidr' => RoutingValidator::normalizeIpv4Cidr(
                    $row['destination_cidr']
                )['canonical_cidr'],
                'action' => 'egress',
                'route_interface_name' => $row['route_interface_name'],
                'server_link_id' => $row['server_link_id'] !== null
                    ? (int) $row['server_link_id']
                    : null,
            ];
        }

        foreach ($policies as &$policy) {
            $policy['hash'] = hash(
                'sha256',
                json_encode($policy['rules'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }
        unset($policy);

        return array_values($policies);
    }
}
