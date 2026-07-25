#!/bin/sh
set -eu

interface="${1:-awg-egress}"
mss="${2:-1200}"
chain="${AWG_MSS_CHAIN:-AWG_EGRESS_MSS}"

remove_direct_rules()
{
    while iptables -t mangle -C FORWARD -i "$interface" -p tcp -j TCPMSS --set-mss "$mss" 2>/dev/null; do
        iptables -t mangle -D FORWARD -i "$interface" -p tcp -j TCPMSS --set-mss "$mss"
    done
    while iptables -t mangle -C FORWARD -o "$interface" -p tcp -j TCPMSS --set-mss "$mss" 2>/dev/null; do
        iptables -t mangle -D FORWARD -o "$interface" -p tcp -j TCPMSS --set-mss "$mss"
    done
    while iptables -t mangle -C FORWARD -i "$interface" -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --set-mss "$mss" 2>/dev/null; do
        iptables -t mangle -D FORWARD -i "$interface" -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --set-mss "$mss"
    done
    while iptables -t mangle -C FORWARD -o "$interface" -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --set-mss "$mss" 2>/dev/null; do
        iptables -t mangle -D FORWARD -o "$interface" -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --set-mss "$mss"
    done
}

remove_direct_rules
iptables -t mangle -N "$chain" 2>/dev/null || true
iptables -t mangle -F "$chain"
iptables -t mangle -A "$chain" -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --set-mss "$mss"
iptables -t mangle -C FORWARD -i "$interface" -j "$chain" 2>/dev/null ||
    iptables -t mangle -I FORWARD 1 -i "$interface" -j "$chain"
iptables -t mangle -C FORWARD -o "$interface" -j "$chain" 2>/dev/null ||
    iptables -t mangle -I FORWARD 1 -o "$interface" -j "$chain"
