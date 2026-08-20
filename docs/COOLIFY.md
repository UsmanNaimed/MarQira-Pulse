# Coolify Deployment — MarQira Pulse

Complete deployment guide for MarQira Pulse on Coolify.

## Phase 3: Backend Infrastructure

See **[PHASE_3_DEPLOYMENT.md](./PHASE_3_DEPLOYMENT.md)** for complete, step-by-step instructions to deploy:
- PostgreSQL 16 (internal, private)
- Redis 7 (internal, private)
- Laravel API at `api.marqira.com`

The guide includes:
- Click-by-click Coolify UI instructions (beginner-friendly)
- Environment variable configuration
- Database migration steps
- Security verification tests
- Backup configuration

## Phase A: Immediate Push-Delivered Updates (connector 1.2.10)

See **[PHASE_A_IMMEDIATE_UPDATES.md](./PHASE_A_IMMEDIATE_UPDATES.md)** §8 for the
full steps. In short: no new env vars; on the API run
`php artisan migrate --force` (adds one nullable `update_command_id` column),
clear config/route caches, rebuild the dashboard, then upload and **activate**
`releases/marqira-connector-1.2.10.zip` under Plugin Releases. Redis is already
required — no change.

## Future Phases

- Phase 5: React Dashboard at `app.marqira.com`
- Phase 7: Plugin Update Server at `updates.marqira.com`
- Phase 9: Origin Bypass Proxy at `proxy.marqira.com`

---

## Quick Reference

### Internal Service Names
```
PostgreSQL: marqira-postgres:5432
Redis:      marqira-redis:6379
API:        marqira-api:9000
```

### External Endpoints
```
API:        https://api.marqira.com
Dashboard:  https://app.marqira.com (Phase 5)
Updates:    https://updates.marqira.com (Phase 7)
n8n:        https://n8n.marqira.com (already deployed)
```

### Critical Security Rules
- PostgreSQL port 5432: **NEVER** publicly accessible
- Redis port 6379: **NEVER** publicly accessible
- All services communicate over internal Docker network
- External access ONLY via HTTPS through Nginx/Traefik
- APP_DEBUG=false in production
- Regular PostgreSQL backups to off-VPS storage
