# Release 1.2.0 — Deployment Runbook (Manual Coolify Steps)

This release ships in **green, deployable increments** pushed to `main`. After
Coolify redeploys `main`, perform the manual step(s) for each increment below.
Increments are additive and non-destructive; existing sites keep working and do
**not** need to reconnect.

> **How to open the API container shell:** In Coolify → your `marqira-api`
> application → **Terminal** (a.k.a. Console / Exec). You should get a shell
> inside the running container. All `php artisan ...` commands below run there.

---

## Increment 1 — Platform roles, ownership, revocation & duplicate prevention

**Commit:** `492a6ce`
**Risk:** Low. Migrations are additive (new nullable columns + one partial
unique index) and reversible. No data is deleted.

### What changed (operationally)
- New `users.platform_role` (`owner` / `subscriber`) and `users.is_active`.
- Existing organization **owners are auto-promoted** to platform Owner during
  migration, so current admins keep full visibility. `ozman.best@gmail.com` is
  always promoted if present.
- New `sites.owner_user_id`, `sites.domain_normalized`, `sites.revoked_at`,
  `sites.revoked_by`. Ownership is backfilled from the enrollment token that
  created each site (where known).
- A **partial unique index** enforces one *active* site per
  (organization, normalized domain). Before the index is created the migration
  **soft-revokes** any pre-existing active duplicates, keeping the most
  recently-seen row (nothing is deleted).

### Manual steps after redeploy

1. **(Recommended) Preview duplicate cleanup — safe, read-only:**
   ```
   php artisan marqira:deduplicate-sites --dry-run
   ```
   This lists every domain that has more than one active site and which rows
   *would* be soft-revoked. It writes nothing. Review the output; if a wrong
   row would be kept, resolve it in the dashboard first.

2. **Run migrations:**
   ```
   php artisan migrate --force
   ```
   Expected new migrations:
   - `2024_01_02_000001_add_platform_role_to_users`
   - `2024_01_02_000002_add_owner_and_revocation_to_sites`
   - `2024_01_02_000003_add_sites_domain_unique_index`
   - `2024_01_02_000004_create_account_invitations_table`

   > The `..._add_sites_domain_unique_index` migration performs the safe
   > soft-revoke of any remaining active duplicates automatically before adding
   > the index, so it is safe even if you skipped step 1.

3. **Verify the primary Owner:**
   ```
   php artisan tinker --execute="echo \App\Models\User::where('email','ozman.best@gmail.com')->value('platform_role');"
   ```
   Should print `owner`. If that account does not exist yet, create it with
   `php artisan marqira:create-admin` (it is created as an Owner).

4. **(Optional) Re-run the dedup command without `--dry-run`** only if you later
   discover duplicates that were enrolled before this release and were not
   caught by the migration:
   ```
   php artisan marqira:deduplicate-sites
   ```

### Rollback
If needed, `php artisan migrate:rollback` reverses these four migrations
(drops the added columns/index/table). No site rows are removed by rolling
back, but any sites soft-revoked during dedup will remain revoked — reactivate
them from the dashboard if required.

### No config/env changes required
This increment introduces **no** new environment variables. Email/SMTP settings
are introduced in a later increment (offline alerting) and are documented then.

---

## Increment 2 — Connector lifecycle: persistent pairing, cron self-heal & self-disconnect on revocation

Plugin version **1.2.0** (display name **MarQira Pulse**; folder/slug/prefix
unchanged at `marqira-connector`). This increment pairs the WordPress connector
with the revocation support shipped in Increment 1.

### What changed (operationally)
- **Self-disconnect on revocation.** When a site is revoked from the dashboard,
  the API already answers its next heartbeat with `HTTP 403
  {"error":"site_revoked","site_revoked":true}` (Increment 1). The 1.2.0
  connector now detects this and **stops its heartbeat cron and clears its stored
  credentials**, so a revoked site goes quiet immediately instead of retrying a
  rejected beat every couple of minutes. Reconnecting requires a fresh enrollment
  code.
- **Plain 403s are safe.** A 403 that is *not* a revocation signal (e.g. a
  transient WAF or permission block) is logged as an ordinary failure and does
  **not** wipe credentials — a healthy site is never silently disconnected.
- **Persistent pairing across updates (confirmed).** Credentials are stored in
  the `marqira_site_credentials` option and preserved on deactivate/upgrade;
  only uninstall removes them. Combined with the existing cron self-heal, updated
  sites keep monitoring with **no reconnection**.
- **"Updates available" now works out of the box.** The API config
  `marqira.plugin.latest_version` now defaults to `1.2.0` (env
  `MARQIRA_PLUGIN_LATEST_VERSION`), so the dashboard flags any site still running
  an older connector.

### Manual steps after redeploy
1. **API:** No migrations. **Optional but recommended** — set (or confirm) the
   env var so the "Updates available" card reflects the shipped connector:
   ```
   MARQIRA_PLUGIN_LATEST_VERSION=1.2.0
   ```
   If the variable is left **blank**, Laravel treats it as empty and the card
   shows 0. If the variable is **absent**, the new `1.2.0` config default applies.
   After changing env in Coolify, redeploy (or run `php artisan config:cache`) so
   the value is picked up.
2. **Connector plugin:** Publish the updated `marqira-connector` folder (v1.2.0)
   to customers as a normal plugin update (same folder/slug). **No customer
   reconnection is required.** Existing enrolled sites keep their credentials and
   simply gain the self-disconnect behavior.

### Verifying revocation end-to-end (optional)
1. Revoke a test site from the dashboard (Increment 1 `DELETE` / disconnect).
2. Within one heartbeat interval (~2 min test cadence) the site's next beat
   receives `403 site_revoked`; the connector clears credentials and unschedules
   its cron. In **Settings → MarQira Connector → Recent Activity** you will see a
   `site_revoked` log entry.

### Rollback
- **API:** revert the `marqira.plugin.latest_version` default (or unset the env
  var). No migrations were added, so there is nothing to roll back on the DB.
- **Connector:** re-publishing the previous 1.1.3 plugin folder restores the old
  behavior; enrolled sites are unaffected either way (credentials persist).

### No destructive changes
No database migrations, no schema changes, and no forced reconnection. Existing
sites continue heartbeating exactly as before, plus the new revocation handling.
