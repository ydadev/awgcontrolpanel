#!/usr/bin/env bash
set -euo pipefail

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

warn() {
  echo "WARNING: $*" >&2
}

command -v docker >/dev/null 2>&1 || fail "docker is not installed or not in PATH"
docker compose version >/dev/null 2>&1 || fail "Docker Compose plugin is not available"

[[ -f .env ]] || fail ".env is missing. Run: cp .env.example .env, then edit secrets"

required_vars=(
  DB_DATABASE
  DB_USERNAME
  DB_PASSWORD
  DB_ROOT_PASSWORD
  ADMIN_EMAIL
  ADMIN_PASSWORD
  JWT_SECRET
)

for var in "${required_vars[@]}"; do
  value="$(grep -E "^${var}=" .env | tail -n 1 | cut -d= -f2- || true)"
  [[ -n "$value" ]] || fail "$var is missing in .env"
  case "$value" in
    replace-*|change-me*|your-*|admin123|rootpassword)
      fail "$var still contains an unsafe example value"
      ;;
  esac
  if [[ "$var" == *PASSWORD && "${#value}" -lt 12 ]]; then
    fail "$var must be at least 12 characters"
  fi
done

jwt_secret="$(grep -E '^JWT_SECRET=' .env | tail -n 1 | cut -d= -f2-)"
if [[ "${#jwt_secret}" -lt 32 ]]; then
  fail "JWT_SECRET must be at least 32 characters"
fi

bind_addr="$(grep -E '^PANEL_HTTP_BIND=' .env | tail -n 1 | cut -d= -f2- || true)"
if [[ "${bind_addr:-127.0.0.1}" == "0.0.0.0" ]]; then
  warn "PANEL_HTTP_BIND=0.0.0.0 exposes the test panel on the VM network"
fi

echo "Preflight OK. You can run: docker compose up -d --build"
