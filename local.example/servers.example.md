# Example Server Inventory

This file shows the format for local server notes. It contains fictional data only.

Real server data should be stored outside Git, for example:

```text
../local/servers.md
```

## VPN Nodes

| Name | Role | Public Address | Internal Address | SSH User | Notes |
| --- | --- | --- | --- | --- | --- |
| vpn-ru-01 | Russian VPN node | 203.0.113.10 | 203.0.113.110 | vpn-admin | Fictional test node |
| vpn-eu-01 | Overseas VPN node | 198.51.100.20 | 198.51.100.120 | vpn-admin | Fictional test node |
| vpn-office-01 | Office or corporate DNS path | 192.0.2.30 | 192.0.2.130 | vpn-admin | Fictional test node |

## Domains

| Name | Domain | Purpose |
| --- | --- | --- |
| panel-test | panel.example.test | Test control panel |
| ru-node-test | vpn-ru-01.example.test | Russian route test node |
| eu-node-test | vpn-eu-01.example.test | Overseas route test node |

## SSH Notes

Use placeholders in public examples:

```text
Host vpn-ru-01.example.test
  HostName 203.0.113.10
  User vpn-admin
  IdentityFile ~/.ssh/example_test_key
```

Do not commit real hostnames, internal domains, users, private keys, or SSH configs.
