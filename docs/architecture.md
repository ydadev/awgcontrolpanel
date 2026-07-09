# Architecture

This document describes the intended architecture for `awgcontrolpanel`. It is a starting point and should be updated after each meaningful design decision.

## Goals

The project should become a corporate control panel for AmneziaWG/WireGuard VPN infrastructure. It should preserve useful ideas from `amneziavpnphp` while moving toward a safer, simpler, multi-node design.

Core future features:

- VPN server management;
- VPN client management;
- client config generation;
- QR code generation;
- enabling and disabling clients;
- client expiration dates;
- traffic limits;
- usage statistics;
- administrator action log;
- user roles;
- LDAP/Active Directory integration;
- multiple VPN nodes;
- route templates;
- safe node control through SSH or a dedicated node agent.

## Main Components

### Control Panel

The panel is the administrative interface. It should provide a clear workflow for managing VPN nodes, users, clients, routes, limits, and audit events.

The panel must not expose secrets in logs, screenshots, public examples, or Git-tracked configuration.

### Database

The database should store only the data required for panel operation:

- administrators and roles;
- VPN nodes;
- VPN clients;
- client state and expiration;
- traffic limits and statistics;
- route templates;
- audit events;
- integration settings.

Sensitive values should be encrypted, minimized, or moved to a dedicated secret store where possible. Database ports should not be exposed publicly.

### VPN Nodes

VPN nodes run AmneziaWG/WireGuard and apply generated configuration. The panel should support more than one node.

Planned node responsibilities:

- keep tunnel configuration active;
- report status and statistics;
- apply client enable or disable operations;
- expose a narrow management surface to the panel.

### Node Operations

The original project may rely on SSH-style operations. This is convenient for early work but must be treated as a risk area.

Preferred future direction:

- short-term: controlled SSH with strict host key checking and least-privilege users;
- long-term: a small node agent with a limited API and explicit authentication.

### Users and Roles

The panel should eventually support role-based access. Example roles:

- owner or super administrator;
- VPN administrator;
- helpdesk operator;
- read-only auditor.

LDAP/Active Directory integration is planned for corporate identity management. Local accounts may still be useful for bootstrap and emergency access.

### API

The API should be the internal contract between the web UI and backend operations. It should validate permissions for each action and record important changes in the audit log.

Potential future integrations:

- LDAP/Active Directory;
- monitoring systems;
- service desk or ticketing tools;
- node-agent API.

## Future Routing Model

The planned routing model is:

- internal corporate resources through office infrastructure or corporate DNS;
- Russian resources through a Russian VPN node;
- other resources through an overseas VPN node.

This is not implemented yet. It should first be described as route templates, then tested on a non-production stand.

## Current Local Inspection

On 2026-07-09, the workspace was arranged as an outer `awgcontrolpanel/` directory with `amneziavpnphp/`, inner `awgcontrolpanel/`, and `local/` subdirectories. The `amneziavpnphp/` directory contains a clean upstream snapshot from `infosave2007/amneziavpnphp` branch `master` and should be used for comparison only.
