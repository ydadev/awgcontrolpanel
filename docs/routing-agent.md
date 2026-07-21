# Routing Agent

The target agent is `awg-routing-agent`, a host-level Ubuntu service.

Phase 1 includes only the panel-side schema and delivery queue. The real agent still needs to be implemented before traffic can be routed through remote egress servers.

Target paths:

```text
/usr/local/bin/awg-routing-agent
/etc/awg-routing-agent/
/var/lib/awg-routing-agent/current/
/var/lib/awg-routing-agent/previous/
/var/log/awg-routing-agent/
```

Required API:

```text
POST /v1/config/apply
GET  /v1/status
GET  /v1/health
POST /v1/conntrack/clear
POST /v1/link/keys/generate
POST /v1/link/keys/rotate
```

The agent must use mTLS, validate signed full configurations, apply nftables atomically, maintain rollback state, and fail closed for unavailable egress links.
