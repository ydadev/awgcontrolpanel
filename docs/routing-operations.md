# Routing Operations

## Phase 1

1. Apply migrations `074` through `082`.
2. Start the panel with Redis, `routing-worker`, and `routing-scheduler`.
3. Open `Routing` as an administrator.
4. Create routing ingresses for policy-routing capable installed protocols.
5. Create server links and IP lists.
6. Optionally create routing user groups, assign users, and save group link permissions. A grouped user cannot create individual route lists.
7. Build revisions through the admin UI or API.

Revisions are stored in `routing_config_revisions`. They are not pushed to a real agent until the agent phase is implemented.

## Rollback

The pre-routing project backup for version `0.1.1` is stored outside Git under `local/backups/`.

For a test server, restore the project files from the backup archive and restart compose. Database rollback requires restoring a database volume or dump made before applying routing migrations.
