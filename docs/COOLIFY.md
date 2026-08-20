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

## Phase B: Critical Error Protection & Automatic Recovery (connector 1.2.11)

See **[PHASE_B_ERROR_PROTECTION.md](./PHASE_B_ERROR_PROTECTION.md)** for the full
architecture. Deploy steps:

1. **API** — run `php artisan migrate --force` (adds one nullable JSON column
   `update_command_recovery` to `sites`). No new env vars. Clear caches:
   `php artisan config:clear && php artisan route:clear`.
2. **Dashboard** — rebuild (`npm ci && npm run build`) and redeploy the static
   assets; the recovery banner ships with the new build.
3. **Connector** — upload and **activate**
   `releases/marqira-connector-1.2.11.zip` under Plugin Releases. On activation
   the connector auto-installs the must-use guard into `wp-content/mu-plugins/`
   (`marqira-guard.php`); confirm it appears under **Plugins → Must-Use** on a
   managed site. No new env vars; Redis unchanged.
4. **Verify** on a staging site using the *Live-site verification* checklist in
   `PHASE_B_ERROR_PROTECTION.md` §11.

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
