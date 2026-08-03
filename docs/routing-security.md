# Routing Security

Implemented protections:

- route pages, status API, and write actions are administrator-only;
- regular users have no personal route list or route-management endpoint;
- every write form validates a CSRF token;
- only normalized IPv4 CIDRs are accepted, with a maximum of 5,000 entries per target;
- exact destination duplicates across active targets are rejected;
- optimistic hashes reject stale concurrent edits;
- a database advisory lock serializes applies per target;
- interface names and persistent paths are validated against strict allowlists;
- remote scripts use fixed apply strategies and shell-escaped list payloads;
- success requires a remote marker and a matching SHA-256 hash;
- routing changes and failures are recorded in the database and audit log;
- private keys, preshared keys, SSH credentials, generated configs, route backups, and database dumps remain outside Git.

The panel still uses the existing SSH management channel to apply routes with elevated privileges. Restrict panel-to-node SSH at the firewall, prefer SSH keys, limit the management account's sudo policy, and keep node credentials encrypted or in a dedicated secret store.
