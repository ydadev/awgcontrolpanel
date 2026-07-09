# Example Environment Variables

This file documents expected environment variables with safe placeholder values only.

Real values should be stored outside Git or in local `.env` files that are ignored by `.gitignore`.

## Application

```env
APP_ENV=local
APP_NAME="AWG Control Panel"
SESSION_NAME=awg_control_panel_session
DEFAULT_LOCALE=ru
PANEL_HTTP_BIND=127.0.0.1
PANEL_HTTP_PORT=8082
```

## Database

```env
DB_HOST=db
DB_PORT=3306
DB_DATABASE=amnezia_panel
DB_USERNAME=amnezia
DB_PASSWORD=change-me-example-only
DB_ROOT_PASSWORD=change-me-root-example-only
```

## Authentication

```env
ADMIN_EMAIL=admin@example.test
ADMIN_PASSWORD=change-me-admin-example-only
JWT_SECRET=replace-with-generated-test-secret
```

## VPN Node Access

```env
NODE_ACCESS_MODE=ssh
NODE_SSH_USER=vpn-admin
NODE_SSH_KEY_PATH=/run/secrets/example_test_key
NODE_STRICT_HOST_KEY_CHECKING=true
```

## LDAP Or Active Directory Future Integration

```env
LDAP_ENABLED=false
LDAP_URL=ldaps://ldap.example.test
LDAP_BASE_DN=dc=example,dc=test
LDAP_BIND_DN=cn=awg-panel,ou=service,dc=example,dc=test
LDAP_BIND_PASSWORD=change-me-example-only
```

## Notes

- Do not reuse example secrets in any real environment.
- Do not commit real `.env` files.
- Prefer generated long random secrets for JWT, sessions, and service credentials.
