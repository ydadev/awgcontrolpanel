# awgcontrolpanel

`awgcontrolpanel` is a planned corporate control panel for AmneziaWG/WireGuard VPN servers. The project is based on [infosave2007/amneziavpnphp](https://github.com/infosave2007/amneziavpnphp), with gratitude to the original project author(s), and is being gradually simplified, hardened, and adapted for private infrastructure.

This repository must not contain real secrets, production IP addresses, internal domains, private keys, VPN client configs, database dumps, or screenshots with sensitive data. Only safe `.example` files with fictional data are allowed.

## Workspace Layout

Expected local workspace:

```text
awgcontrolpanel/
├── amneziavpnphp/          # clean upstream copy for comparison only
├── awgcontrolpanel/        # working repository
└── local/                  # private local data outside Git
```

Current state on 2026-07-09:

- The outer `awgcontrolpanel/` directory now contains the expected three-folder workspace layout.
- Public project files are stored in the inner `awgcontrolpanel/` directory.
- A private `local/context-full.md` file exists outside the inner project directory for full local context.
- The `git` command was not available in PATH during inspection, so Git status could not be checked from the terminal.
- The `amneziavpnphp/` directory contains a clean upstream snapshot downloaded from `infosave2007/amneziavpnphp` branch `master`.
- The upstream snapshot was downloaded as a GitHub archive, so it does not include Git history.
- The upstream source has been copied into the inner `awgcontrolpanel/` working project as the starting point for customization.
- No application business logic was changed.

## Architecture Summary

Planned high-level components:

- web control panel for administrators;
- database for users, VPN nodes, clients, permissions, and audit events;
- one or more AmneziaWG/WireGuard VPN nodes;
- safe integration layer for node operations, initially possibly SSH-based and later preferably node-agent-based;
- API layer for panel actions and future integrations;
- optional LDAP/Active Directory integration for corporate users and roles.

Future routing goal:

- internal corporate resources go through office infrastructure or corporate DNS;
- Russian resources go through a Russian VPN node;
- all other resources go through an overseas VPN node.

The safe control-plane foundation for this routing is implemented as phase 1. It stores desired routing state, permissions, user groups, and revisions, but it does not yet change the live VPN packet path until the routing agent/data-plane phase is completed.

## Security Decisions

- Real `.env` files are ignored by Git.
- `.env.example` remains allowed for safe placeholder configuration.
- Local private data belongs outside the repository in `local/`.
- Database dumps, backups, private keys, and logs are ignored.
- Docker build context excludes local secrets and generated artifacts through `.dockerignore`.
- Security risks discovered during future review should be recorded in `docs/security-plan.md` before they are silently changed.

## Current Status

Safe project preparation has been completed:

- added `.gitignore` with secret, dump, key, log, and temporary-file exclusions;
- added public project memory in this file;
- added initial architecture, security, and test deployment documents;
- added safe local examples under `local.example/`.
- moved the public project files into the inner `awgcontrolpanel/` working project directory;
- added `../local/context-full.md` for private unsummarized context outside the working project.
- downloaded the clean upstream `infosave2007/amneziavpnphp` snapshot into `../amneziavpnphp/` for comparison.
- copied the upstream application source into this working project;
- merged `.gitignore` rules so local secrets, dumps, generated configs, dependencies, and build artifacts stay out of Git while source migrations remain trackable;
- recorded first imported-source security risks in `docs/security-plan.md`.
- prepared a first Ubuntu 24.04 test deployment path in `docs/deployment-ubuntu-24.04.md`;
- added `docs/install-from-github.md` for installing the inner project directly from GitHub;
- removed the SQL-created default admin from fresh installs;
- made SSH-key-only server records possible on fresh installs by allowing nullable server passwords;
- removed host publishing for MySQL in the test compose file;
- added a `vendor_data` volume so Composer dependencies from the image are not hidden by the project bind mount;
- added `.dockerignore` to keep secrets and local artifacts out of Docker build context.
- added `scripts/preflight-test-server.sh` for Ubuntu VM readiness checks before first compose start.

Current application changes tested on 2026-07-10:

- the working project is published to `https://github.com/ydadev/awgcontrolpanel`;
- the panel was installed and tested from GitHub on an Ubuntu 24.04 test VM;
- a separate Ubuntu 24.04 VPN node VM was used for WireGuard Standard and AmneziaWG 2.0 testing;
- WireGuard Standard client creation was fixed for native host `wg0` peers;
- AmneziaWG 2.0 install/build handling was fixed for the test environment;
- client pages now show additional raw WireGuard-style QR/text connection data:
  - `wireguard-standard` clients receive normal WireGuard app-compatible config;
  - `awg2` and AmneziaWG-family clients receive AmneziaWG app-compatible config while keeping the existing Amnezia VPN app and `vpn://` outputs.
- regular users no longer add or manage servers directly; administrators assign access to specific servers and may separately allow users to create their own connection configs.
- VPN client records are treated as user-owned connections; administrators choose the connection owner during creation, while regular users can only create connections for their own account when allowed.
- removing a user's access to a server revokes/blocks that user's active client configs on that server.
- phase 1 of the individual routing subsystem is in progress: schema, routing UI/API scaffolding, routing user groups, CIDR validation, outbox/revision workers, Redis service, agent documentation, and rollback notes are present; the existing VPN data plane is not changed by this phase.
- routing groups are mutually exclusive per user. When a user belongs to a routing group, group link permissions and limits apply, and individual route-list creation is blocked in the user panel.

Current release target on 2026-07-20:

- version tag: `v0.2.0`;
- local and server-side tracked files were checked before publication so real `.env` files, `local/`, private keys, database dumps, SSH credentials, and generated VPN configs stay out of Git;
- routing revision delivery state was reconciled after manual live deployment so the panel shows only applied/superseded revisions and no stale pending delivery jobs;
- the Vienna egress route set was verified against the full configured CIDR list: 1133 expected CIDRs, 1133 live WireGuard `AllowedIPs`, and 1133 live kernel routes;
- a routed test client path from the VPN client subnet successfully reached `www.youtube.com` over HTTPS through the Vienna egress path.

## Major Changes

### 2026-07-09

- Created the initial safe repository structure.
- Added documentation for project goals, architecture, security plan, and test deployment.
- Added `local.example/` examples with fictional server and environment data.
- Restructured the workspace into `amneziavpnphp/`, `awgcontrolpanel/`, and `local/`.
- Added private local full-context storage in `local/context-full.md`.
- Downloaded the clean upstream `infosave2007/amneziavpnphp` snapshot from branch `master` into `amneziavpnphp/`.
- Copied the upstream source into the inner `awgcontrolpanel/` working project.
- Preserved project documentation and safe examples while importing application files.
- Merged ignore rules and allowed `migrations/*.sql` as source files.
- Recorded initial imported-source security risks without changing behavior.
- Reviewed original installation documentation and recorded additional install-readiness risks.
- Prepared the project for an isolated Ubuntu 24.04 test VM.
- Added GitHub clone installation instructions.
- Added `.dockerignore`.
- Hardened `.env.example` and `docker-compose.yml` for test deployment.
- Removed the default SQL admin user and made admin creation depend on `.env`.
- Made `vpn_servers.password` nullable for SSH-key-only server records on fresh installs.
- Added a preflight script for the Ubuntu 24.04 test VM.
- Confirmed that no business logic was changed during this step.

### 2026-07-10

- Published the working project to GitHub as `ydadev/awgcontrolpanel`.
- Installed the panel from GitHub on the Ubuntu 24.04 test server.
- Tested WireGuard Standard and AmneziaWG 2.0 on a separate Ubuntu 24.04 VPN node.
- Fixed install/client-generation issues found during test deployment.
- Added raw WireGuard-style QR Code and text connection blocks to client pages.
- Documented that this project is based on `infosave2007/amneziavpnphp` and added attribution/thanks.
- Added per-user server access controls for regular users, including optional self-service config creation and automatic client blocking when server access is removed.
