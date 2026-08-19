# Routing Security

Implemented protections:

- route pages, status API, and write actions are administrator-only;
- regular users have no personal route list or route-management endpoint;
- every write form validates a CSRF token;
- domain patterns are normalized to lowercase DNS suffix patterns and restrict wildcard placement;
- only normalized IPv4 CIDRs are accepted, with bounded domain, CIDR, DNS-cache and nft-set sizes;
- private/reserved addresses learned from DNS are never dynamically marked; explicit CIDR rules may route them;
- optimistic hashes reject stale concurrent edits;
- a database advisory lock serializes applies per source-server module;
- interface names and persistent paths are validated against strict allowlists;
- remote scripts use shell-escaped values and base64 file payloads;
- the resolver binds only VPN/bridge interfaces and is not exposed on the public interface;
- the first peer/config transition keeps `Table = off`, creates a node-local backup and restores old routes on failure;
- success requires active services, an nftables table and a remote marker;
- routing changes and failures are recorded in the database and audit log;
- private keys, preshared keys, SSH credentials, generated configs, route backups, and database dumps remain outside Git.

The panel still uses the existing SSH management channel to apply routes with elevated privileges. Restrict panel-to-node SSH at the firewall, prefer SSH keys, limit the management account's sudo policy, and keep node credentials encrypted or in a dedicated secret store.
