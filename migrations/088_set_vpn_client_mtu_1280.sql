-- Keep client packets within nested VPN paths that use an MTU of 1280.
UPDATE protocols
SET output_template = REPLACE(
        output_template,
        'DNS = {{dns_servers}}

[Peer]',
        'DNS = {{dns_servers}}
MTU = 1280

[Peer]'
    ),
    updated_at = NOW()
WHERE slug = 'wireguard-standard'
  AND output_template NOT LIKE '%MTU = 1280%';

UPDATE protocols
SET output_template = REPLACE(
        output_template,
        'PrivateKey = {{private_key}}
Jc = {{Jc}}',
        'PrivateKey = {{private_key}}
MTU = 1280
Jc = {{Jc}}'
    ),
    updated_at = NOW()
WHERE slug = 'awg2'
  AND output_template NOT LIKE '%MTU = 1280%';
