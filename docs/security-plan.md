# Security Plan

This document tracks known and expected security risks. Risk items should be recorded here before silent fixes are made.

## Repository Safety Rules

Never commit real:

- server IP addresses;
- internal infrastructure domains;
- logins;
- passwords;
- tokens;
- private SSH keys;
- VPN client configs;
- database dumps;
- real `.env` files;
- screenshots with sensitive data.

Only `.example` files with fictional data may be committed.

## Current Safeguards

- `.env` and `.env.*` are ignored.
- `.env.example` is explicitly allowed.
- `local/`, `secrets/`, `private/`, and `backups/` are ignored.
- database dumps and backups are ignored.
- private key file patterns are ignored.
- logs and temporary files are ignored.
- `.dockerignore` excludes `.env`, Git metadata, dependencies, logs, backups, keys, dumps, and generated VPN artifacts from Docker build context.

## Risk Register

| Risk | Status | Plan |
| --- | --- | --- |
| Real secrets committed to Git | Mitigated by initial `.gitignore`; still requires discipline | Keep real data in `local/` outside Git. Use only safe examples in the repository. |
| SSH passwords stored in application database | Found after upstream import | `inc/VpnServer.php` stores server passwords; prefer key-based or agent-based access. Encrypt or eliminate stored secrets. |
| Root SSH access from panel | To review after source import | Replace with least-privilege users and narrowly scoped commands. |
| Docker-in-Docker or privileged containers | Still open for test stand | `docker-compose.yml` still starts privileged Docker-in-Docker for protocol testing. Keep it only on an isolated VM until replaced or sandboxed. |
| Publicly exposed database ports | Mitigated for test compose | MySQL host port publishing was removed from `docker-compose.yml`; keep database access internal only. |
| Unsafe default passwords | Mitigated for test compose | `.env.example` now uses placeholders and `docker-compose.yml` requires `.env` database values. Continue removing stale upstream examples from README/API docs. |
| Default admin from SQL migration overrides `.env` intent | Mitigated for fresh installs | `migrations/001_init.sql` no longer inserts `admin@amnez.ia`; runtime seeding uses `ADMIN_EMAIL` and `ADMIN_PASSWORD`. |
| Disabled SSH host key checking | Found after upstream import | `inc/VpnServer.php`, `inc/VpnClient.php`, and `inc/ServerMonitoring.php` use `StrictHostKeyChecking=no`; require host key verification and documented key rotation. |
| Weak JWT or session secrets | Found after upstream import | `.env.example` contains a weak placeholder JWT secret; require strong generated secrets and rotation procedure. |
| SSH-key-only server creation conflicts with schema | Mitigated for fresh installs | `migrations/001_init.sql` now creates `vpn_servers.password` as nullable. Existing databases need a migration before SSH-key-only use. |
| Docker build context may include secrets | Mitigated | `.dockerignore` was added. Keep real secrets outside the repository and verify build context before server builds. |
| Sensitive data in logs | To review during implementation | Redact tokens, keys, configs, and personal data from logs. |
| VPN client configs exposed in UI or files | To review during implementation | Limit access by role, expiration, and audit trail. |

## Review Checklist For Future Code Work

- Check whether configuration files contain placeholders only.
- Check whether Docker ports expose databases or management services.
- Check whether node operations use strict SSH host key verification.
- Check whether privileged operations can be replaced with a node agent.
- Check whether audit logging records administrator actions without leaking secrets.
- Check whether backups and dumps are excluded from Git and stored securely.

## Notes From Initial Preparation

On 2026-07-09, upstream source files were copied into the inner working project. A first quick scan recorded risk areas above, but no application behavior has been changed yet.

During the install-readiness review, the project was judged suitable only for an isolated first test stand after local environment preparation. It is not ready for a production or internet-exposed server without fixing the recorded risks.
