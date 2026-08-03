# Routing Testing

Required control-plane checks:

- migration `085` applies twice without changing the result;
- only `default` remains and its member count equals the user count;
- new users are assigned to `default`;
- legacy profiles, personal lists, permissions, pending revisions, and delivery jobs are removed or superseded;
- PHP, Twig, and generated shell syntax checks pass;
- regular users cannot open route management or the routing status API;
- IPv4 CIDRs normalize and deduplicate correctly, while empty/invalid/IPv6 lists are rejected;
- stale expected hashes and concurrent apply locks are enforced;
- desired/applied hashes and statuses change correctly on success and failure.

Required data-plane checks for each target:

- persistent list count/hash;
- live kernel route count and representative route lookups;
- tunnel/interface and handshake health;
- forwarding/NAT counters;
- client DNS, office access, domestic/direct traffic, overseas egress, HTTPS, packet loss, and MTU behavior;
- route recovery after tunnel/container and source-node restart;
- restoration from the pre-change database and route/config backups.
