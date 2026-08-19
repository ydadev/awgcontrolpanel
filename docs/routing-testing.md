# Routing Testing

Required dynamic-module control-plane checks:

- migration `089` copies every legacy target and CIDR, leaves modules disabled, and is idempotent;
- PHP, Twig, and generated shell syntax checks pass;
- regular users cannot open route management or the routing status API;
- domain wildcards and IPv4 CIDRs normalize and deduplicate correctly;
- stale path hashes and concurrent module applies are rejected;
- generated nftables configuration accepts nested CIDR using `auto-merge`;
- dnsmasq backend selection rejects `no-nftset` and falls back to RAM-backed `ipset`;
- a live DNS answer populates the selected dynamic set and resolves through the expected marked policy table;
- DNS resolver configuration never binds the public interface;
- desired/applied hashes and statuses change correctly on success and failure.

Required data-plane checks for each module/path:

- DNS passthrough response equality through the configured upstream;
- domain lookup insertion and TTL expiry in the expected nft set;
- static CIDR and domain priority overlaps;
- `ip rule`, policy-table default route and marked route lookup;
- tunnel/interface and handshake health;
- forwarding/NAT counters;
- client DNS, office access, domestic/direct traffic, overseas egress, HTTPS, packet loss, and MTU behavior;
- route recovery after tunnel/container and source-node restart;
- disabling and re-enabling the module;
- restoration from the pre-change database and node-local peer/route backups.
