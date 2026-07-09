# Test Deployment

This document describes a safe test deployment approach. It intentionally uses placeholders and fictional values only.

## Principles

- Do not use production servers for the first test stand.
- Do not copy real `.env` files into the repository.
- Do not commit generated VPN client configs.
- Do not expose database ports to the public internet.
- Use fictional examples from `local.example/` as templates, then keep real values in `../local/`.

## Expected Local Layout

```text
awgcontrolpanel/
├── amneziavpnphp/
├── awgcontrolpanel/
└── local/
    ├── servers.md
    └── env.md
```

The outer `local/` directory should be next to the inner repository directory, not inside it.

## Preparation Steps

1. Place a clean upstream copy in `amneziavpnphp/` for comparison.
2. Keep this repository in `awgcontrolpanel/`.
3. Create `../local/` for real private notes and test secrets.
4. Use `local.example/servers.example.md` as a format reference only.
5. Use `local.example/env.example.md` as a variable reference only.
6. Create real runtime `.env` files locally, but do not commit them.

## Test Stand Checklist

- Use a non-production VPN node.
- Use a test domain such as `vpn-node-01.example.test`.
- Use documentation-only IP ranges such as `192.0.2.0/24`, `198.51.100.0/24`, or `203.0.113.0/24` in public examples.
- Use a dedicated test administrator account.
- Rotate all test secrets before any public demo or shared screenshot.
- Verify that `git status` does not include `.env`, dumps, keys, or private notes before committing.

## Docker Notes

For the current test compose setup:

- database services are not published to the host;
- `.env` is required for database credentials;
- `PANEL_HTTP_BIND=127.0.0.1` is the safer default for SSH-tunnel testing;
- `vendor_data` keeps Composer dependencies available while the project directory is bind-mounted;
- persistent Docker volumes must not be committed;
- logs must not contain tokens, keys, or generated client configs.

## First Run

Use the Ubuntu 24.04 test deployment guide:

```text
docs/deployment-ubuntu-24.04.md
```

Short version:

```bash
cp .env.example .env
# edit .env and replace all example secrets
./scripts/preflight-test-server.sh
docker compose up -d --build
docker compose ps
docker compose logs -f web
```
