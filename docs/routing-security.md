# Routing Security

Current implemented protections:

- backend RBAC entry point through `Auth::can()`;
- admin routing UI is admin-only;
- regular users only get `/my/routes`;
- IPv6 CIDR is rejected in phase 1;
- user destination CIDR is normalized before saving;
- broad/default routes can be denied by link permission;
- private, loopback, multicast, link-local and other protected IPv4 ranges are rejected for user routes;
- routing changes are written to `routing_audit_log` where implemented.

Still required for production:

- CSRF protection across all write forms;
- mTLS between panel and agent;
- signed canonical JSON configurations;
- replay protection;
- rate limiting;
- firewall allowlist for agent API;
- root-shell avoidance in agent operations;
- private server-link keys stored only on VPN nodes.
