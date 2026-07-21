<?php

class RoutingCompiler
{
    public static function compileServerPolicies(int $serverId): array
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT
                r.id,
                COALESCE(e.canonical_cidr, r.destination_cidr) AS destination_cidr,
                r.action,
                r.server_link_id,
                r.priority,
                r.is_locked,
                r.target_type,
                r.ip_list_id,
                a.subject_type,
                a.user_id,
                a.group_id,
                a.client_id
            FROM routing_rules r
            JOIN routing_profiles p ON p.id = r.profile_id
            LEFT JOIN routing_profile_assignments a ON a.profile_id = p.id
            LEFT JOIN routing_ingresses i ON i.id = a.ingress_id
            LEFT JOIN routing_ip_list_entries e ON e.ip_list_id = r.ip_list_id AND r.target_type = "ip_list"
            WHERE r.enabled = 1
              AND p.enabled = 1
              AND (i.server_id = ? OR a.ingress_id IS NULL)
              AND (r.target_type <> "ip_list" OR e.id IS NOT NULL)
            ORDER BY r.is_locked DESC, r.priority ASC, r.id ASC
        ');
        $stmt->execute([$serverId]);
        $rules = [];
        foreach ($stmt->fetchAll() as $rule) {
            if (!empty($rule['destination_cidr'])) {
                $normalized = RoutingValidator::normalizeIpv4Cidr($rule['destination_cidr']);
                $rule['destination_cidr'] = $normalized['canonical_cidr'];
            }
            $rules[] = $rule;
        }

        $policyHash = hash('sha256', json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return [[
            'policy_id' => substr($policyHash, 0, 16),
            'hash' => $policyHash,
            'rules' => $rules,
        ]];
    }
}
