# Routing Testing

Phase 1 test scope:

- migrations apply cleanly on MySQL 8.0;
- PHP syntax checks pass;
- admin cannot be bypassed for `/routing`;
- users can only open `/my/routes`;
- IPv4 CIDR normalization works;
- IPv6 is rejected;
- protected IPv4 ranges are rejected for user routes;
- outbox events create config revisions;
- generated revision JSON has a stable SHA-256 hash.

Production data-plane testing still required:

- nftables check and atomic apply;
- DIRECT, EGRESS and BLOCK behavior;
- no ingress SNAT for remote egress;
- egress NAT only on final exit;
- conntrack stickiness;
- fail-closed blackhole route;
- reboot recovery;
- rollback after apply failure;
- 1,000 clients and 50,000 compiled intervals.
