# Deployment — Latest (multi-user accounts, tenant isolation, theme updates)

Manual Coolify steps are required. Perform them in this order after Coolify
redeploys `main`.

## Redeploy order

1. **`marqira-api`** first (DB migrations must run before the new dashboard hits
   the new endpoints).
2. **`marqira-dashboard`** second (rebuild so the Users page, maintenance
   buttons, updates indicator and `/account-setup/:token` route ship).

## 1. API — `marqira-api`

### Run migrations

Open the `marqira-api` container shell in Coolify and run:

```
php artisan migrate --force
```

This applies three new migrations:

- `2024_01_07_000001_add_account_fields_to_users` — `website_limit`,
  `last_login_at`, `plan` on `users`.
- `2024_01_07_000002_add_update_inventory_to_sites` — `core_update_available`,
  `plugin_updates_count`, `theme_updates_count`, `updates_checked_at` on `sites`.
- `2024_01_07_000003_backfill_site_ownership` — **backfills** every existing
  site's `owner_user_id` to its organization's Owner. Existing production sites
  are assigned to the Owner automatically; **no reconnect / re-enrollment is
  needed** (§12).

### Environment variable (optional but recommended)

Account invitation / setup links are built as `{APP_FRONTEND_URL}/account-setup/{token}`.
Set this in the `marqira-api` Coolify env UI so links point at the dashboard,
not the API host:

```
APP_FRONTEND_URL=https://app.marqira.com
```

If unset it falls back to `APP_URL`. After changing env, redeploy or run
`php artisan config:cache`.

No other env changes. No queue/scheduler changes for this release.

## 2. Dashboard — `marqira-dashboard`

Standard redeploy (rebuild). No env changes. New public route
`/account-setup/:token` is client-side; no server rewrite needed beyond the
existing SPA fallback.

## 3. Publish connector 1.2.4 (required for theme updates)

Theme updates and the update-inventory heartbeat require connector **1.2.4**.
Core and plugin maintenance still work on 1.2.3; theme buttons stay disabled
with guidance on older connectors.

Upload and activate the new release via the owner **Plugin Releases** page (or
the `/api/dashboard/plugin-releases` endpoint):

- File: `marqira-connector-1.2.4.zip`
- Version: `1.2.4`
- SHA-256: `eaafe8710f2f344a2d674ef14e74cc12426f4ddca32306123dd443da17d567ca`
- Size: `59205` bytes
- Mark **active** so update-check serves it to connected sites.

## Verification

On `marqira-api`:

```
php artisan migrate:status | tail -5        # 3 new migrations = Ran
php artisan tinker --execute="echo \App\Models\Site::whereNull('owner_user_id')->count();"   # expect 0
```

In the dashboard:

- Owner sees the **Users** and **Plugin Releases** nav items; a subscriber does not.
- Websites overview shows the amber "Updates available" indicator on sites with
  pending updates.
- A site's Updates tab shows all three maintenance buttons; each is enabled only
  when that update type is available.
- Creating a user returns a setup link; opening it in a browser reaches the
  account-setup page and lets the invitee set a password.
