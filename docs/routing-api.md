# Routing API

The shared routing model currently exposes one read-only endpoint:

```text
GET /api/routing/status
```

It returns aggregate module/path status and requires an authenticated administrator.

Module, path, domain and CIDR writes are intentionally available only through the administrator web form. Forms use CSRF validation, optimistic edit hashes and a per-module database advisory lock. Applying one path recompiles and applies the whole server module so priorities cannot be partially updated.
