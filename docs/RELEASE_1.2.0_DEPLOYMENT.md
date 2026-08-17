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
