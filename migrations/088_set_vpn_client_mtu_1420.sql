-- Use the standard client WireGuard MTU for new WG and AWG2 profiles.
UPDATE protocols
SET output_template = REPLACE(
        output_template,
        'MTU = 1280',
        'MTU = 1420'
    ),
    updated_at = NOW()
WHERE slug IN ('wireguard-standard', 'awg2')
  AND output_template LIKE '%MTU = 1280%';

UPDATE protocols
SET output_template = REPLACE(
        output_template,
        'DNS = {{dns_servers}}',
        'DNS = {{dns_servers}}
MTU = 1420'
    ),
    updated_at = NOW()
WHERE slug IN ('wireguard-standard', 'awg2')
  AND output_template NOT LIKE '%MTU = %';
