# Feature modules

The feature module registry is the first compatibility layer for moving optional functionality out of the monolithic entry point without changing current behavior.

## Configuration

```dotenv
# Empty means: use each manifest's default_enabled value.
MODULES_ENABLED=

# Applied after MODULES_ENABLED.
MODULES_DISABLED=routing
```

Unknown module IDs and attempts to disable a required module fail during boot. This makes configuration mistakes visible instead of silently leaving half of a module active.

Inspect the effective state inside the web container:

```bash
php bin/modules.php
```

## Current modules

| ID | Required | Default | Enforced surfaces |
|---|---|---|---|
| `core` | yes | enabled | shared portal runtime |
| `routing` | no | enabled | navigation, HTML routes, API route and routing background entry points |
| `ldap` | no | enabled | login hook, settings UI/routes, connection test and synchronization job |

Only modules whose full surface is listed here may be disabled. A manifest alone does not make legacy code safely optional.

## Safe routing disable semantics

Disabling `routing`:

- hides its navigation item;
- returns 404 from its HTML/API endpoints before controller code executes;
- makes routing CLI jobs exit successfully without DB or remote-server mutation;
- preserves routing tables, database records and already applied remote-node rules.

The last property is intentional. Module disable is reversible and must not behave like uninstall. Removing live remote routing state requires a separate explicit cleanup operation with a rollback plan.

## Safe LDAP disable semantics

Disabling `ldap` hides both settings links, prevents LDAP table reads from the shared settings page, returns neutral 404 for all LDAP routes, skips the LDAP login hook and makes the synchronization CLI exit before DB/network work. Existing LDAP configuration, mappings and previously synchronized local user rows are preserved. Local password authentication remains available.

After changing module environment settings, recreate the web container so its environment is refreshed. Scheduled service/timer definitions may also be disabled to avoid harmless repeated no-op invocations.

## Contract for a new optional module

1. Add `modules/<id>/module.php` with a stable ID, dependencies, safe default and optional `routes` filename.
2. Return a callable from `modules/<id>/routes.php`; register every HTML/API route through `Router::moduleGet`, `modulePost`, `modulePut` or `moduleDelete`.
3. Guard every navigation element and cross-module UI fragment using the registry state.
4. Make every cron, worker and CLI entry point check the same module state before touching DB/network state.
5. Keep `disable`, `uninstall` and `delete data` as separate operations.
6. Define owned tables/config/permissions/jobs and migration postconditions.
7. Add tests proving a disabled route does not execute its handler and core still boots.
8. Verify both default-enabled and disabled configurations before deployment.

## Current limitation

Most legacy features are still coupled to shared pages and services and are therefore part of `core`. They must not be added to `MODULES_DISABLED` until their complete surface has been extracted and tested. The next candidates are translation automation and protocol editor; monitoring is embedded in server/client templates and needs UI decomposition before it can be safely switched off.
