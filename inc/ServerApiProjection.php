<?php

/**
 * Builds the public representation of a VPN server.
 *
 * Database rows intentionally contain deployment credentials and private VPN
 * material. API responses must be assembled from an allowlist so a newly
 * added database column cannot become public by accident.
 */
final class ServerApiProjection
{
    private const COMMON_FIELDS = [
        'id',
        'name',
        'host',
        'status',
        'vpn_port',
        'vpn_subnet',
        'dns_servers',
        'deployed_at',
        'last_check_at',
        'created_at',
        'updated_at',
        'can_create_clients',
    ];

    private const ADMIN_FIELDS = [
        'user_id',
        'user_email',
        'port',
        'username',
        'container_name',
        'error_message',
        'install_protocol',
    ];

    private const PROTOCOL_FIELDS = ['id', 'slug', 'name'];

    public static function one(array $server, bool $includeAdminMetadata = false): array
    {
        $fields = $includeAdminMetadata
            ? array_merge(self::COMMON_FIELDS, self::ADMIN_FIELDS)
            : self::COMMON_FIELDS;

        $result = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $server)) {
                $result[$field] = $server[$field];
            }
        }

        $result['protocols'] = [];
        foreach (($server['protocols'] ?? []) as $protocol) {
            if (!is_array($protocol)) {
                continue;
            }

            $publicProtocol = [];
            foreach (self::PROTOCOL_FIELDS as $field) {
                if (array_key_exists($field, $protocol)) {
                    $publicProtocol[$field] = $protocol[$field];
                }
            }
            $result['protocols'][] = $publicProtocol;
        }

        return $result;
    }

    public static function collection(array $servers, bool $includeAdminMetadata = false): array
    {
        return array_map(
            static fn(array $server): array => self::one($server, $includeAdminMetadata),
            $servers
        );
    }
}
