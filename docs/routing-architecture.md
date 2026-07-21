# Routing Architecture

This subsystem stores desired per-connection IPv4 routing policy in MySQL and prepares versioned server configurations for a future host-level `awg-routing-agent`.

Phase 1 implements the safe control-plane foundation:

- policy-routing capability flag on protocols;
- routing ingress and IP pool records;
- server link records;
- reusable IPv4 lists;
- profiles, rules, user and group link permissions;
- routing user groups;
- outbox, revision and audit tables;
- admin and user UI entry points;
- JSON config builder and revision creator.

Phase 1 intentionally does not change existing WireGuard or AmneziaWG data-plane behavior. Existing server installation and connection generation continue to work through the original panel code.

The production design remains:

```text
VPN connection IP -> policy_id -> destination CIDR -> direct/block/fwmark -> routing table -> egress
```

Packet handling must happen in the Linux kernel with nftables, conntrack marks and policy routing. PHP, MySQL and Redis must never be involved per packet.

## User Groups

Routing groups are an administrative policy layer above individual user settings. A user can belong to only one routing group at a time. When membership exists, the panel resolves allowed server links, route limits, and default-route permission from the group and ignores individual `routing_user_link_permissions` for that user. The user UI becomes read-only for route-list creation, so group policy cannot be overridden by personal settings.
