# Ubuntu 24.04 Test Deployment

This guide is for the first isolated test VM. Do not use it as a production hardening checklist.

## VM

Recommended minimum:

- Ubuntu Server 24.04 LTS;
- 2 CPU;
- 2-4 GB RAM;
- 20-40 GB disk;
- firewall closed by default;
- SSH access only from trusted admin IPs.

## Install Docker

```bash
sudo apt update
sudo apt install -y ca-certificates curl gnupg git openssl

sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | \
  sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
```

Optional for a non-root deploy user:

```bash
sudo usermod -aG docker "$USER"
newgrp docker
```

## Copy Project

Either clone from GitHub by following:

```text
docs/install-from-github.md
```

Or copy the inner working project directory to the VM:

```text
C:\Users\daud\Documents\awgcontrolpanel\awgcontrolpanel
```

Target example on the VM:

```bash
mkdir -p ~/awgcontrolpanel
cd ~/awgcontrolpanel
```

## Create Environment

```bash
cp .env.example .env
```

Generate local test secrets:

```bash
openssl rand -base64 32
```

Edit `.env` and replace at least:

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

For safer first testing keep:

```env
PANEL_HTTP_BIND=127.0.0.1
PANEL_HTTP_PORT=8082
```

Then open the panel through an SSH tunnel from your workstation:

```bash
ssh -L 8082:127.0.0.1:8082 user@TEST_VM_IP
```

Open:

```text
http://127.0.0.1:8082
```

On an isolated VM only, you may set:

```env
PANEL_HTTP_BIND=0.0.0.0
```

## Start

```bash
docker compose up -d --build
docker compose ps
docker compose logs -f web
```

The Docker image installs Composer dependencies during build. A named `vendor_data` volume keeps `/var/www/html/vendor` available even though the project directory is bind-mounted for editing.

## First Login

Use the `ADMIN_EMAIL` and `ADMIN_PASSWORD` values from `.env`.

There should be no default `admin123` account on a fresh database.

## Reset Test Stand

This deletes the test database and Docker state for this project:

```bash
docker compose down -v
docker compose up -d --build
```

## Known Test-Only Risks

- Docker-in-Docker still runs privileged for protocol deployment testing.
- SSH host key verification is not hardened yet.
- SSH password storage is not hardened yet.
- No HTTPS is configured in this test compose file.

Record new findings in `docs/security-plan.md` before changing behavior.
