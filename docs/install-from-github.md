# Install From GitHub To Test Server

This document assumes the inner `awgcontrolpanel/` directory is published as the repository root.

This project is based on [infosave2007/amneziavpnphp](https://github.com/infosave2007/amneziavpnphp). Thanks to the original project author(s) for the base panel.

Do not publish the outer workspace directory. The GitHub repository should contain this directory:

```text
C:\Users\daud\Documents\awgcontrolpanel\awgcontrolpanel
```

It should not contain:

- `C:\Users\daud\Documents\awgcontrolpanel\local`;
- `C:\Users\daud\Documents\awgcontrolpanel\amneziavpnphp`;
- real `.env` files;
- real server notes, IP addresses, passwords, private keys, client configs, dumps, or screenshots.

## First Clone On Ubuntu 24.04 Test VM

```bash
git clone https://github.com/ydadev/awgcontrolpanel.git
cd awgcontrolpanel
```

Install Docker first by following:

```text
docs/deployment-ubuntu-24.04.md
```

Then create local secrets:

```bash
cp .env.example .env
nano .env
```

Replace all placeholder values:

- `DB_PASSWORD`;
- `DB_ROOT_PASSWORD`;
- `ADMIN_EMAIL`;
- `ADMIN_PASSWORD`;
- `JWT_SECRET`.

Run preflight:

```bash
chmod +x scripts/preflight-test-server.sh
./scripts/preflight-test-server.sh
```

Start:

```bash
docker compose up -d --build
docker compose ps
docker compose logs -f web
```

With the safer default `PANEL_HTTP_BIND=127.0.0.1`, use an SSH tunnel:

```bash
ssh -L 8082:127.0.0.1:8082 user@TEST_VM_IP
```

Open:

```text
http://127.0.0.1:8082
```

## Updating From GitHub

For a disposable test stand:

```bash
git pull
docker compose up -d --build
```

If migrations changed and the database already exists, review `migrations/README.md` before applying migrations manually.

To fully reset the test stand:

```bash
docker compose down -v
docker compose up -d --build
```

This deletes the test database volumes.

## Current Client Config Outputs

The copied/customized project keeps the existing Amnezia VPN app outputs and adds raw WireGuard-style outputs on client pages:

- WireGuard Standard clients: normal WireGuard QR Code and text configuration.
- AmneziaWG/AWG2 clients: AmneziaWG app-compatible QR Code and text configuration.
- AWG2 clients still keep the existing `vpn://` QR Code and text URL.

## User Access Model

Regular users do not add or manage VPN servers. Administrators create/manage servers, then assign each regular user access to selected servers in Settings -> Users.

For every assigned server, the administrator can also allow or deny self-service connection config creation. If server access is removed from a user, that user's active configs on the removed server are disabled.

In the original codebase these records are still named `clients` internally. In this fork they should be understood as VPN connection records owned by system users. Administrators choose the owner when creating a connection; regular users can only create connections for their own account when they have permission.
