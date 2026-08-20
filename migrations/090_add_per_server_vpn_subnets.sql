-- Allow WireGuard-family protocols to use a unique address pool per server.
UPDATE protocols
SET install_script = REPLACE(
        REPLACE(
            REPLACE(
                install_script,
                'VPN_SUBNET="${PROTOCOL_VPN_SUBNET:-10.8.1.0/24}"',
                'VPN_SUBNET="${SERVER_VPN_SUBNET:-${PROTOCOL_VPN_SUBNET:-10.8.1.0/24}}"'
            ),
            'SERVER_ADDR="${VPN_SUBNET%0/24}1/24"',
            'NETWORK_BASE="${VPN_SUBNET%/*}"\nPREFIX="${VPN_SUBNET#*/}"\nSERVER_ADDR="${NETWORK_BASE%.*}.1/${PREFIX}"'
        ),
        'echo "Server Public Key: ${PUBLIC_KEY}"',
        'echo "Server Public Key: ${PUBLIC_KEY}"\necho "VPN Subnet: ${VPN_SUBNET}"'
    )
WHERE slug = 'wireguard-standard'
  AND install_script NOT LIKE '%SERVER_VPN_SUBNET%';

UPDATE protocols
SET install_script = REPLACE(
        REPLACE(
            REPLACE(
                install_script,
                'MTU=${MTU:-1280}',
                'MTU=${MTU:-1280}\nVPN_SUBNET="${SERVER_VPN_SUBNET:-${PROTOCOL_VPN_SUBNET:-10.8.2.0/23}}"\nNETWORK_BASE="${VPN_SUBNET%/*}"\nPREFIX="${VPN_SUBNET#*/}"\nSERVER_ADDR="${NETWORK_BASE%.*}.1/${PREFIX}"'
            ),
            'echo "Address = 10.8.2.1/23"',
            'echo "Address = $SERVER_ADDR"'
        ),
        'echo "Server Public Key: $PUBLIC_KEY"',
        'echo "Server Public Key: $PUBLIC_KEY"\necho "VPN Subnet: $VPN_SUBNET"'
    )
WHERE slug = 'awg2'
  AND install_script NOT LIKE '%SERVER_VPN_SUBNET%';

-- Keep AWG2 server configuration private for both fresh and adopted installs.
UPDATE protocols
SET install_script = REPLACE(
        install_script,
        'if [ -f /opt/amnezia/awg2/awg0.conf ]; then',
        'if [ -f /opt/amnezia/awg2/awg0.conf ]; then\n  chmod 600 /opt/amnezia/awg2/awg0.conf'
    )
WHERE slug = 'awg2'
  AND install_script NOT LIKE '%then\n  chmod 600 /opt/amnezia/awg2/awg0.conf%';

UPDATE protocols
SET install_script = REPLACE(
        install_script,
        '} > /opt/amnezia/awg2/awg0.conf',
        '} > /opt/amnezia/awg2/awg0.conf\nchmod 600 /opt/amnezia/awg2/awg0.conf'
    )
WHERE slug = 'awg2'
  AND install_script NOT LIKE '%conf\nchmod 600 /opt/amnezia/awg2/awg0.conf%';
