# Routing Operations

## Phase 1

1. Apply migrations `074` through `082`.
2. Start the panel with Redis, `routing-worker`, and `routing-scheduler`.
3. Open `Routing` as an administrator.
4. Create routing ingresses for policy-routing capable installed protocols.
5. Create server links and IP lists.
6. Optionally create routing user groups, assign users, and save group link permissions. A grouped user cannot create individual route lists.
7. Build revisions through the admin UI or API.

Revisions are stored in `routing_config_revisions`. They are not pushed to a real agent until the agent phase is implemented.

## AWG2 inter-server transport

An egress link can use an AmneziaWG 2.0 userspace tunnel when ordinary WireGuard is filtered or unstable between nodes. The tested layout uses:

- `amneziawg-go` in a host-network Docker container;
- a dedicated point-to-point subnet and interface;
- numeric endpoint addresses and a configurable UDP listener, including UDP 443;
- MTU 1280 and TCP MSS 1200 in both forwarding directions;
- source NAT on the ingress node and masquerading on the egress node;
- the original WireGuard link kept available for rollback.

Use `scripts/awg2-userspace-entrypoint.sh` as the container entrypoint. It avoids the slow `awg-quick` parser for configurations with a large `AllowedIPs` list, starts `amneziawg-go` directly, applies a pre-stripped `awg setconf` file, runs node-specific `up.sh` and `down.sh` hooks, and writes a `ready` marker after all routes and firewall rules are installed. The container needs host networking, `NET_ADMIN`, and `/dev/net/tun`.

Install `scripts/awg2-host-mss.sh` as a host-level systemd oneshot instead of applying TCP MSS rules from the container image. This keeps host firewall behavior independent of the image's `iptables` implementation and restores the SYN-only MSS chain at boot without granting the container host PID access or `SYS_ADMIN`.

The entrypoint requires `AWG_ADDRESS`. Optional variables are `AWG_INTERFACE`, `AWG_CONFIG_DIR`, `AWG_SETCONF_FILE`, `AWG_UP_SCRIPT`, `AWG_DOWN_SCRIPT`, `AWG_READY_FILE`, and `AWG_MTU`. Keep private keys, preshared keys, endpoint addresses, route lists, and generated configuration outside Git.

Before switching production routes, verify all of the following:

1. Packet loss for small, 1200-byte, and near-MTU probes.
2. The selected destinations resolve to the AWG egress interface.
3. Ingress SNAT, egress masquerading, forwarding, and SYN-only MSS rules.
4. HTTP and bulk-download tests through the tunnel source address.
5. Container restart recovery and the `ready` marker on both nodes.
6. Matching desired and applied routing versions and hashes.

## Rollback

The pre-routing project backup for version `0.1.1` is stored outside Git under `local/backups/`.

For a test server, restore the project files from the backup archive and restart compose. Database rollback requires restoring a database volume or dump made before applying routing migrations.
