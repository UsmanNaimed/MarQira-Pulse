# Fix: False OFFLINE Alerts — Independent Active HTTP Verification

**Branch:** `main` · **Commit:** `d8841ab` · **Pushed:** yes (`bf1f268..d8841ab`, remote `main` = `d8841ab`)

---

## 1. Root cause

Monitoring is **push-based**. The WordPress connector fires heartbeats to
`POST /api/v1/heartbeat` from **WP-Cron, which only runs when the site receives
traffic**. Offline detection (`marqira:check-stale-sites`, scheduled every
minute) previously marked a site OFFLINE **purely because its `last_heartbeat_at`
was older than `offline_threshold_minutes` (30) or null**.

That single signal — *heartbeat silence* — is not proof the website is down. It
also goes quiet when the site is simply **idle / on free-tier / sleeping**, when
**WP-Cron is disabled**, when the **connector or an outbound firewall** blocks
the call, or when **our own monitoring worker/network** hiccups. Every one of
those produced a **false OFFLINE alert** while the site was perfectly reachable,
which is exactly the symptom reported.

## 2. What changed

A stale heartbeat is now only a **trigger to independently verify** the site,
never an offline verdict on its own.

| File | Change |
|------|--------|
| `app/Services/Monitoring/SiteHealthChecker.php` *(new)* | Active HTTP(S) probe of the real site (Laravel `Http`/Guzzle). Never throws. Configurable timeout, connect-timeout, in-probe retries + backoff. Follows redirects, verifies TLS. Classifies the outcome (see §3). |
| `app/Services/Monitoring/HealthCheckResult.php` *(new)* | Immutable verdict: `UP` / `DOWN` / `INCONCLUSIVE` + category (`ok`, `http_4xx`, `http_5xx`, `dns`, `connection`, `timeout`, `tls`, `no_url`, `probe_error`) + http code / latency. |
| `app/Console/Commands/CheckStaleSitesCommand.php` | Rewritten. Stale candidates are probed; state changes only on **confirmed** evidence, with a failure threshold, a recovery threshold, and a batch worker-network guard. Legacy "stale ⇒ offline" path preserved behind a flag. Atomic conditional UPDATEs keep single-fire alert guarantees. Repeat-alert logic unchanged. |
| `config/marqira.php` | New `heartbeat.active_check` block, all env-driven (`MARQIRA_ACTIVE_CHECK_*`) with safe defaults. |
| `database/migrations/2024_01_09_000001_add_active_check_tracking_to_sites.php` *(new)* | Adds `consecutive_check_failures`, `consecutive_check_successes`, `last_active_check_at/status/reason/http_code/latency_ms` to `sites` (all nullable/defaulted; reversible). |
| `app/Models/Site.php` | New columns added to `$fillable` + `$casts`. |
| `app/Http/Controllers/Api/V1/HeartbeatController.php` | A real heartbeat now also resets `consecutive_check_failures`/`successes` to 0 — an immediate recovery path. |
| Tests | `tests/Unit/Services/Monitoring/SiteHealthCheckerTest.php` (13), `tests/Feature/Monitoring/ActiveUptimeVerificationTest.php` (8) added; `CheckStaleSitesCommandTest`/`OfflineAlertTest` updated for the new default. |

## 3. Reliability behavior

**When a site is probed:** only when its heartbeat is stale (older than
`offline_threshold_minutes`, default 30) or never seen — **healthy, chatty sites
are never probed**, so request volume/cost stays low. Already-offline sites are
also probed so they can auto-recover even if the connector never returns.

**Probe classification (`SiteHealthChecker`):**

| Outcome | Verdict | Rationale |
|---------|---------|-----------|
| 2xx / 3xx (redirects followed) | **UP** | Reachable. |
| 4xx (403, 404, 429, …) | **UP** | Server is responding — not an outage. Rate-limiting/auth walls don't mean down. |
| 5xx | **DOWN** (`http_5xx`) | Server error. |
| DNS failure | **DOWN** (`dns`, *network*) | |
| Connection refused | **DOWN** (`connection`, *network*) | |
| Timeout | **DOWN** (`timeout`, *network*) | |
| TLS/SSL failure | **DOWN** (`tls`, **not** network) | Site-specific, so it can legitimately mark a site offline. |
| No probeable URL / local error | **INCONCLUSIVE** | Never changes state. |

In-probe **retries** (`retries`, default 1; `retry_backoff_ms`, default 750) let
a one-off blip or a **cold-starting** free-tier host pass within a single run.

**State machine (`CheckStaleSitesCommand`):**

- **Stays / returns ONLINE** — probe UP. A quiet-but-reachable site keeps its
  ONLINE status and `last_seen_at` is refreshed. **This is the false-positive
  fix.**
- **Marked OFFLINE** — only after `failure_threshold` (default **3**)
  **consecutive confirmed** DOWN probes. Below the threshold it just increments
  `consecutive_check_failures`; a single transient failure never alerts. On the
  threshold hit it atomically claims the transition, writes an audit record with
  `verified: true`, and sends **exactly one** initial offline alert.
- **Recovering → ONLINE** — an offline site needs `recovery_threshold` (default
  **2**) consecutive successful probes, **or any real heartbeat** (which recovers
  it immediately and clears the streak). Prevents flapping. A recovery email is
  sent only if an offline alert had actually been sent.
- **Batch worker-network guard** — if in one run at least `batch_guard_min_sites`
  (default 3) probed sites **and** at least `batch_guard_failure_ratio` (default
  0.75) of them fail with **network-level** errors (DNS/connect/timeout), the run
  is treated as a **monitoring-side** problem: it records the observation as
  inconclusive and makes **no** offline transitions and does **not** advance any
  failure counter. This stops our own outages being reported as customer
  outages.

**Speed vs. safety:** genuine outages are still caught fast — confirmed within
`failure_threshold` minutes (~3 min) of the 30-min stale trigger, since the
monitor runs every minute. Detection is not slowed down; it is made *correct*.

**Timeouts:** `timeout_seconds` (15) total, `connect_timeout_seconds` (10) —
generous enough for a sleeping host's cold start without hanging the run.

**Config knobs (all optional, safe defaults):**
`MARQIRA_ACTIVE_CHECK_ENABLED` (true), `_TIMEOUT` (15), `_CONNECT_TIMEOUT` (10),
`_RETRIES` (1), `_RETRY_BACKOFF_MS` (750), `_FAILURE_THRESHOLD` (3),
`_RECOVERY_THRESHOLD` (2), `_BATCH_GUARD_MIN` (3), `_BATCH_GUARD_RATIO` (0.75),
`_USER_AGENT`. Setting `MARQIRA_ACTIVE_CHECK_ENABLED=false` restores the exact
legacy behavior.

## 4. Testing

Interpreter: PHP 8.2 CLI, in-memory SQLite test DB, Redis running. All probes are
faked (`Http::fake()`) — **no real network** is touched.

```
php vendor/bin/pest
Tests: 239 passed (773 assertions)
```

- Baseline before changes: **218 passed**.
- Added **13** unit tests (`SiteHealthCheckerTest`) — every classification path
  incl. redirect-to-200, 4xx→up, 5xx→down, DNS/connection/timeout→down+network,
  TLS→down-not-network, no-URL→inconclusive, cold-start (503→200 via retry),
  URL resolution precedence.
- Added **8** feature tests (`ActiveUptimeVerificationTest`) end-to-end through
  the command: **(a)** stale + reachable ⇒ stays ONLINE, no alert *(the core
  fix)*; **(b)** 403 ⇒ online; **(c)** single DOWN ⇒ not flipped; **(d)** 3
  consecutive DOWN ⇒ OFFLINE + exactly one alert + `verified:true` audit;
  **(e)** recovery only after 2 successes + recovery email; **(f)** batch guard
  flips none of 4 DNS-failing sites; **(g)** inconclusive never flips;
  **(h)** heartbeat resets the failure streak.
- Existing suites updated so their single-run expectations still hold; no
  regressions.

## 5. Git

- **Branch:** `main`
- **Commit:** `d8841ab099301c2a070e95676240a41045600d84`
- **Message:** `fix(monitoring): eliminate false-offline alerts via independent active HTTP verification` (full body in the commit).
- **Push:** succeeded — `bf1f268..d8841ab  main -> main`; `git ls-remote` confirms remote `refs/heads/main` = `d8841ab`.
- Only the 11 task files were committed. The untracked `design/` directory (a separate, paused task) was **not** touched. No secrets in the diff.

## 6. Coolify deployment (tailored to this project)

The API is deployed on Coolify as a **Dockerfile** application (Build Pack =
Dockerfile, Dockerfile Path `apps/api/Dockerfile`, Build Context `apps/api`,
Port `9000`; PostgreSQL + Redis are internal services). Deploy this change as
follows:

1. **Trigger a redeploy.** The push to `main` (`d8841ab`) triggers Coolify's
   auto-deploy if webhooks are enabled. Otherwise open the API application in
   Coolify → **Deploy** (Redeploy). Coolify rebuilds the image from
   `apps/api/Dockerfile` and restarts the container.

2. **Run the database migration** (adds the new `sites` tracking columns — this
   is required before the new command logic is exercised). In Coolify open the
   API application → **Terminal** (or **Execute Command**) on the running
   container and run:
   ```
   php artisan migrate --force
   ```
   Confirm with `php artisan migrate:status` that
   `2024_01_09_000001_add_active_check_tracking_to_sites` shows **Ran**.

3. **Refresh the cached config** so the new `active_check` block is picked up
   (the image caches config at build time; a rebuild already does this, but if
   you change any env var after deploy, re-run it):
   ```
   php artisan config:cache
   ```

4. **Ensure the scheduler is running** — the fix lives in the every-minute
   `marqira:check-stale-sites` command. There must be a process/cron running
   `php artisan schedule:run` every minute (a Coolify **Scheduled Task** with
   `* * * * *`, or a sidecar/cron running it against the API container). Verify:
   ```
   php artisan schedule:list
   ```
   should list `marqira:check-stale-sites` running every minute.

5. **Ensure the queue worker is running** — offline/recovery emails are queued
   mailables. A worker must be processing the Redis queue:
   ```
   php artisan queue:work redis --tries=3 --sleep=3
   ```
   (run as a persistent Coolify process/worker resource, not a one-off).

6. **Verify outbound HTTP egress from the API container** — the new probe makes
   real outbound HTTP(S) requests to monitored sites. Confirm the container is
   allowed egress on 80/443:
   ```
   php artisan tinker --execute="echo \Illuminate\Support\Facades\Http::timeout(10)->get('https://example.com')->status();"
   ```
   should print `200`. If it fails, open outbound 80/443 for the API service.

7. **(Optional) tune via environment variables.** All `MARQIRA_ACTIVE_CHECK_*`
   vars have safe defaults, so **no env change is required**. To adjust, add them
   under the API app's **Environment Variables** in Coolify and redeploy (or
   `php artisan config:cache`). To temporarily fall back to legacy behavior set
   `MARQIRA_ACTIVE_CHECK_ENABLED=false`.

8. **Post-deploy sanity check.** Watch the scheduler logs for the command's
   summary line (`Transitioned N site(s) offline; sent M repeat alert(s).`) and
   confirm a known idle-but-reachable site now stays ONLINE. Audit records for
   real outages carry `verified: true`.

No secrets, no breaking schema changes (columns are additive/nullable), and the
change is fully backward compatible.
