<?php

final class LegacyBaseline093Postconditions
{
    /** @return array<string, bool> */
    public static function verify(PDO $pdo): array
    {
        $checks = [
            'wireguard_template' => "SELECT COUNT(*) FROM protocols WHERE slug='wireguard-standard' "
                . "AND output_template LIKE '%PrivateKey = {{private_key}}%' "
                . "AND output_template LIKE '%PublicKey = {{server_public_key}}%' "
                . "AND output_template LIKE '%MTU = 1420%'",
            'wireguard_dns_variable' => "SELECT COUNT(*) FROM protocol_variables v "
                . "JOIN protocols p ON p.id=v.protocol_id "
                . "WHERE p.slug='wireguard-standard' AND v.variable_name='dns_servers'",
            'awg2_pool' => "SELECT COUNT(*) FROM protocols WHERE slug='awg2' "
                . "AND JSON_UNQUOTE(JSON_EXTRACT(definition,'$.metadata.vpn_subnet'))='10.8.2.0/23'",
            'openvpn_pool' => "SELECT COUNT(*) FROM protocols WHERE slug='openvpn' "
                . "AND JSON_UNQUOTE(JSON_EXTRACT(definition,'$.metadata.vpn_subnet'))='10.8.4.0/24'",
            'awg2_template_mtu' => "SELECT COUNT(*) FROM protocols WHERE slug='awg2' "
                . "AND output_template LIKE '%MTU = 1420%' AND output_template NOT LIKE '%MTU = 1280%'",
            'wireguard_server_subnet' => "SELECT COUNT(*) FROM protocols WHERE slug='wireguard-standard' "
                . "AND install_script LIKE '%SERVER_VPN_SUBNET%' AND install_script LIKE '%VPN Subnet:%'",
            'awg2_server_subnet' => "SELECT COUNT(*) FROM protocols WHERE slug='awg2' "
                . "AND install_script LIKE '%SERVER_VPN_SUBNET%' AND install_script LIKE '%VPN Subnet:%' "
                . "AND install_script LIKE '%chmod 600 /opt/amnezia/awg2/awg0.conf%'",
            'moderator_role' => "SELECT COUNT(*) FROM user_roles WHERE name='moderator'",
            'moderator_translations' => "SELECT COUNT(*) = 6 FROM translations "
                . "WHERE category='users' AND key_name='role_moderator' "
                . "AND locale IN ('en','ru','es','de','fr','zh')",
            'routing_rule_text_columns' => "SELECT COUNT(*) = 2 FROM information_schema.COLUMNS "
                . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='routing_policy_paths' "
                . "AND COLUMN_NAME IN ('domain_rules_text','cidr_rules_text') AND DATA_TYPE='mediumtext' AND IS_NULLABLE='YES'",
            'client_allowed_ips_contract' => "SELECT COUNT(*) = 2 FROM information_schema.COLUMNS "
                . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vpn_clients' "
                . "AND ((COLUMN_NAME='allowed_ips_mode' AND COLUMN_TYPE='varchar(32)' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='full') "
                . "OR (COLUMN_NAME='config' AND DATA_TYPE='mediumtext' AND IS_NULLABLE='YES'))",
            'dynamic_routing_tables' => "SELECT COUNT(*) = 3 FROM information_schema.TABLES "
                . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('routing_policy_modules','routing_policy_paths','routing_policy_rules')",
            'legacy_targets_mapped' => "SELECT COUNT(*) = 0 FROM routing_route_targets t "
                . "LEFT JOIN routing_policy_paths p ON p.legacy_target_id=t.id WHERE p.id IS NULL",
        ];

        $result = [];
        foreach ($checks as $name => $sql) {
            $result[$name] = (bool) $pdo->query($sql)->fetchColumn();
        }
        return $result;
    }
}
