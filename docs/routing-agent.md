# Routing Agent

The earlier phase-1 design reserved a separate `awg-routing-agent` and asynchronous delivery queue. That path is not active in shared-route mode.

Migration `085` makes the administrator's save/apply request authoritative and applies each managed list directly through the VPN server's existing SSH management connection. The legacy scheduler and worker stay passive so existing Compose deployments do not need an immediate service-layout change.

A dedicated node agent may replace SSH in a future security hardening release. It is not required for the current `default` group route targets.
