# Install From GitHub To Test Server

This document assumes the inner `awgcontrolpanel/` directory is published as the repository root.

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
