-- Keep standard WireGuard client settings aligned with the AWG2 client defaults.
UPDATE protocols
SET output_template = '[Interface]
PrivateKey = {{private_key}}
Address = {{client_ip}}/32
DNS = {{dns_servers}}

[Peer]
PublicKey = {{server_public_key}}
PresharedKey = {{preshared_key}}
AllowedIPs = 0.0.0.0/0, ::/0
Endpoint = {{server_host}}:{{server_port}}
PersistentKeepalive = 25'
WHERE slug = 'wireguard-standard';

INSERT INTO protocol_variables
    (protocol_id, variable_name, variable_type, default_value, description, required)
SELECT
    p.id,
    'dns_servers',
    'string',
    '1.1.1.1, 1.0.0.1',
    'DNS servers for generated client configurations',
    false
FROM protocols p
WHERE p.slug = 'wireguard-standard'
  AND NOT EXISTS (
      SELECT 1
      FROM protocol_variables v
      WHERE v.protocol_id = p.id AND v.variable_name = 'dns_servers'
  );
