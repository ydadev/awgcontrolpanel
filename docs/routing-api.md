# Routing API

The shared routing model currently exposes one read-only endpoint:

```text
GET /api/routing/status
```

It returns aggregate target/group status and requires an authenticated administrator.

Route-list writes are intentionally available only through the administrator web form, which includes CSRF validation and an optimistic edit hash. The legacy endpoint that created asynchronous server revisions was removed when migration `085` introduced direct shared-route application.
