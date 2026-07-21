# Routing API

Implemented phase 1 endpoints:

```text
GET  /api/routing/status
POST /api/routing/servers/{server_id}/revision
```

`GET /api/routing/status` returns routing counts and requires an authenticated user with `routing.view`.

`POST /api/routing/servers/{server_id}/revision` builds a pending revision for a server and requires `routing.force_sync`, which is currently admin-only.

Future API work:

- CRUD for ingresses;
- CRUD for links;
- CRUD for IP lists and entries;
- profile/rule management;
- user route management;
- conntrack clear requests;
- agent status and health views.
