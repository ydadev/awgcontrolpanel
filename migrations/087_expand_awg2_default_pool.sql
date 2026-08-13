-- Expand the default AWG2 pool without changing existing client /32 addresses.
-- Existing deployed servers still require the coordinated runtime rollout.
UPDATE protocols
SET
    definition = JSON_MERGE_PATCH(
        IF(COALESCE(JSON_TYPE(definition), '') = 'OBJECT', definition, JSON_OBJECT()),
        JSON_OBJECT('metadata', JSON_OBJECT('vpn_subnet', '10.8.2.0/23'))
    ),
    install_script = REPLACE(
        REPLACE(install_script, '10.8.1.1/24', '10.8.2.1/23'),
        '10.8.2.1/24',
        '10.8.2.1/23'
    )
WHERE slug = 'awg2';

-- 10.8.3.0/24 is part of the expanded AWG2 /23. Reserve 10.8.4.0/24
-- for future OpenVPN installations. Existing OpenVPN deployments are untouched.
UPDATE protocols
SET
    definition = JSON_MERGE_PATCH(
        IF(COALESCE(JSON_TYPE(definition), '') = 'OBJECT', definition, JSON_OBJECT()),
        JSON_OBJECT('metadata', JSON_OBJECT('vpn_subnet', '10.8.4.0/24'))
    ),
    install_script = REPLACE(install_script, '10.8.3.0/24', '10.8.4.0/24')
WHERE slug = 'openvpn';
