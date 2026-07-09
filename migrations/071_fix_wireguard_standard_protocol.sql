-- Fix WireGuard Standard protocol so it performs a real native wg-quick install.
-- The original script had a split "docker run -d" command that exited with Docker
-- usage text while the panel still treated it as a successful install.

UPDATE protocols
SET
  is_active = 1,
  ubuntu_compatible = 1,
  definition = JSON_OBJECT(
    'metadata', JSON_OBJECT(
      'container_name', '',
      'port_range', JSON_ARRAY(30000, 65000),
      'vpn_subnet', '10.8.1.0/24'
    )
  ),
  install_script = '#!/bin/bash
set -euo pipefail

WG_IFACE="${WG_IFACE:-wg0}"
VPN_PORT="${SERVER_PORT:-51820}"
VPN_SUBNET="${PROTOCOL_VPN_SUBNET:-10.8.1.0/24}"
SERVER_ADDR="${VPN_SUBNET%0/24}1/24"
CONFIG_DIR="/etc/wireguard"
CONFIG_FILE="${CONFIG_DIR}/${WG_IFACE}.conf"
DEFAULT_IFACE="$(ip route | awk ''/default/ {print $5; exit}'')"
DEFAULT_IFACE="${DEFAULT_IFACE:-eth0}"

if ! command -v wg >/dev/null 2>&1 || ! command -v wg-quick >/dev/null 2>&1; then
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y wireguard wireguard-tools iptables
fi

mkdir -p "$CONFIG_DIR"
umask 077
PRIVATE_KEY="$(wg genkey)"
PUBLIC_KEY="$(printf "%s" "$PRIVATE_KEY" | wg pubkey)"
PRESHARED_KEY="$(wg genpsk)"

systemctl stop "wg-quick@${WG_IFACE}" >/dev/null 2>&1 || true

cat > "$CONFIG_FILE" << EOF
[Interface]
PrivateKey = ${PRIVATE_KEY}
Address = ${SERVER_ADDR}
ListenPort = ${VPN_PORT}
SaveConfig = false
PostUp = sysctl -w net.ipv4.ip_forward=1 >/dev/null; iptables -t nat -C POSTROUTING -s ${VPN_SUBNET} -o ${DEFAULT_IFACE} -j MASQUERADE 2>/dev/null || iptables -t nat -A POSTROUTING -s ${VPN_SUBNET} -o ${DEFAULT_IFACE} -j MASQUERADE; iptables -C FORWARD -i ${WG_IFACE} -j ACCEPT 2>/dev/null || iptables -A FORWARD -i ${WG_IFACE} -j ACCEPT; iptables -C FORWARD -o ${WG_IFACE} -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || iptables -A FORWARD -o ${WG_IFACE} -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT
PostDown = iptables -t nat -D POSTROUTING -s ${VPN_SUBNET} -o ${DEFAULT_IFACE} -j MASQUERADE 2>/dev/null || true; iptables -D FORWARD -i ${WG_IFACE} -j ACCEPT 2>/dev/null || true; iptables -D FORWARD -o ${WG_IFACE} -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || true
EOF

chmod 600 "$CONFIG_FILE"
systemctl enable --now "wg-quick@${WG_IFACE}"

echo "WireGuard Standard installed successfully"
echo "Port: ${VPN_PORT}"
echo "Server Public Key: ${PUBLIC_KEY}"
echo "Preshared Key: ${PRESHARED_KEY}"
',
  uninstall_script = '#!/bin/bash
set -euo pipefail

WG_IFACE="${WG_IFACE:-wg0}"
systemctl disable --now "wg-quick@${WG_IFACE}" >/dev/null 2>&1 || true
rm -f "/etc/wireguard/${WG_IFACE}.conf"
echo "{\"success\":true,\"message\":\"WireGuard Standard uninstalled\"}"
'
WHERE slug = 'wireguard-standard';
