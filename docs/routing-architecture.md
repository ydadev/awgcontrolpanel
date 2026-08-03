# Routing Architecture

The active routing model is shared and administrator-managed. It is not tied to a user, VPN connection, or personal IP list.

## Default group

- Migration `085_create_shared_route_targets.sql` removes legacy profiles, individual/group link permissions, personal route lists, and old memberships.
- One system group named `default` remains.
- Every existing user is assigned to `default`.
- A database trigger assigns every newly created user to `default`.
- Regular users do not receive a routing menu or route-editing endpoint.

## Route targets

`routing_route_targets` binds one editable IPv4 list to:

- the `default` group;
- the source routing ingress;
- a destination/interface;
- an apply strategy and persistent route-list file;
- desired/applied hashes, status, error, and apply timestamp.

The current deployment has two targets:

| Source | Destination | Transport | Interface | Apply strategy |
|---|---|---|---|---|
| `kazan1` | `vienna2` | AmneziaWG 2.0, UDP 443 | `awg-egress` | Linux route file |
| `kazan1` | `office1` | WireGuard, UDP 51835 | `office1` | WireGuard config |

The Vienna target atomically replaces `/opt/amnezia/awg-egress/routes.txt`, adds new routes before removing stale routes, and verifies the file hash.

The office target updates the managed route file, the peer `AllowedIPs`, persistent `PostUp`/`PostDown` hooks, live kernel routes, and the required source NAT rules. The service requires exactly one peer on the managed interface and verifies the written list hash.

## Apply flow

1. The administrator submits one IPv4 CIDR per line.
2. The panel normalizes and deduplicates the list.
3. An optimistic hash rejects a stale browser edit.
4. The desired list is saved and marked `pending`.
5. A per-target database advisory lock serializes apply operations.
6. The list is applied over the server's existing SSH management connection.
7. The panel records the applied hash, timestamp, audit event, and synchronous revision.

Legacy worker/scheduler containers remain compatible with existing Compose installations, but become passive after shared-route migration `085`.
