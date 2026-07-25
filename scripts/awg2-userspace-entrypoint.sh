#!/bin/sh
set -eu

: "${AWG_ADDRESS:?AWG_ADDRESS is required}"

interface="${AWG_INTERFACE:-awg-egress}"
config_dir="${AWG_CONFIG_DIR:-/opt/amnezia/awg-egress}"
setconf_file="${AWG_SETCONF_FILE:-${config_dir}/${interface}.setconf}"
up_script="${AWG_UP_SCRIPT:-${config_dir}/up.sh}"
down_script="${AWG_DOWN_SCRIPT:-${config_dir}/down.sh}"
ready_file="${AWG_READY_FILE:-${config_dir}/ready}"
mtu="${AWG_MTU:-1280}"
awg_pid=""

cleanup()
{
    trap - TERM INT EXIT
    rm -f "$ready_file"
    "$down_script" 2>/dev/null || true
    ip link delete "$interface" 2>/dev/null || true
    if [ -n "$awg_pid" ]; then
        kill "$awg_pid" 2>/dev/null || true
        wait "$awg_pid" 2>/dev/null || true
    fi
}

trap 'cleanup; exit 0' TERM INT
trap cleanup EXIT

export WG_I_PREFER_BUGGY_USERSPACE_TO_POLISHED_KMOD=1
export WG_PROCESS_FOREGROUND=1

rm -f "$ready_file"
rm -f "/var/run/wireguard/${interface}.sock" "/run/wireguard/${interface}.sock"
ip link delete "$interface" 2>/dev/null || true

amneziawg-go "$interface" &
awg_pid=$!

attempt=0
until ip link show "$interface" >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 50 ]; then
        echo "AWG interface did not appear: $interface" >&2
        exit 1
    fi
    sleep 0.1
done

awg setconf "$interface" "$setconf_file"
ip address replace "$AWG_ADDRESS" dev "$interface"
ip link set mtu "$mtu" up dev "$interface"
"$up_script"
touch "$ready_file"

while kill -0 "$awg_pid" 2>/dev/null; do
    sleep 5 &
    wait $!
done

echo "amneziawg-go stopped unexpectedly" >&2
exit 1
