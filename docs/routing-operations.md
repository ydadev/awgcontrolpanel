# Routing Operations

## Editing routes

1. Sign in as an administrator.
2. Open `Routing`.
3. Select the `Routes` tab.
4. Edit the destination list under `kazan1 -> vienna2` or `kazan1 -> office1`.
5. Enter one IPv4 address or CIDR per line. A plain address is normalized to `/32`.
6. Select `Save and apply`.
7. Confirm that the target status is `Applied` and the route count is correct.

Saving changes the live packet path. Take a database dump and back up the route/config files on the source node before a large replacement.

## Groups

The `Groups` tab is informational in the shared model. `default` is the only active routing group, all users belong to it, and new users are assigned by a database trigger. `Synchronize` repairs membership if records were imported manually.

## Validation

After applying either list, verify:

1. desired and applied hashes match in `routing_route_targets`;
2. the persistent route file count and SHA-256 hash match the panel;
3. representative destinations resolve to the expected interface with `ip route get`;
4. the inter-server tunnel has a recent handshake;
5. forwarding and NAT counters increase for test traffic;
6. routes recover after the relevant tunnel/container restart.

For `office1`, also verify the peer `AllowedIPs`, the managed `PostUp`/`PostDown` hooks, and access to the office network.

For `vienna2`, verify the `awg-egress` ready marker, MTU/MSS rules, public egress address, HTTPS access, and packet loss with small and near-MTU probes.

## Failure behavior

- Invalid or empty lists are rejected before SSH execution.
- A stale browser edit is rejected by its expected hash.
- Concurrent applies to the same target are serialized.
- New routes are installed before stale routes are removed.
- A missing interface, missing config, multiple office peers, SSH failure, or hash mismatch marks the target `failed`.
- A failed target keeps the desired database list so the administrator can correct it and apply again.

## Rollback

Restore the database dump and project archive made before migration `085` to return to the legacy schema behavior. For a route-only rollback, restore the previous route/config files on the source node and bring the affected tunnel back up.
