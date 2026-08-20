# Phase A — Immediate, Push-Delivered Updates with Granular Live Status

**Branch:** `main` · **Connector:** `1.2.10` · **Scope:** dashboard-triggered
plugin / theme / WordPress-core / connector-self updates that **start within
seconds** instead of waiting minutes for the next WP-Cron heartbeat.

---

## 1. Problem

Previously an update requested from the dashboard was delivered **only by the
pull channel**: the API set the command to `pending`, and the site picked it up
on its next heartbeat. Heartbeats run from **WP-Cron, which only fires when the
site receives a visitor**. On low-traffic sites a requested update could sit
`pending` for many minutes with no visible progress, and the UI showed a single
coarse "queued / in progress" state with no way to tell *why* nothing was
happening or whether it had silently died.

## 2. What changed (summary)

Updates now use **two delivery channels** plus **granular lifecycle reporting**
and **self-healing** so a command can never be stuck forever:

1. **Push channel (new, primary):** on click the API immediately signs and POSTs
   the command straight to the site's REST API. The connector validates it,
   acknowledges acceptance, and starts a background worker **without waiting for
   a visitor**. Typical start latency: seconds.
2. **Pull channel (fallback):** if the site's connector is older than `1.2.10`,
   or the push request fails (unreachable, timeout, non-2xx), the command stays
   `pending` and is delivered on the next heartbeat exactly as before. Nothing
   regresses for old connectors.
3. **Granular status:** Queued → Starting → Downloading → Installing →
   Verifying → Completed, plus terminal Failed / Rolled Back — surfaced live in
   the dashboard with a step progress bar.
4. **Never stuck:** job locking, stale-lock recovery, duplicate-command
   protection, a server-side stale-command reconciler, and one-shot job tokens.

## 3. Files changed

### API (`apps/api`)

| File | Change |
|------|--------|
| `app/Models/Site.php` | New granular status constants (`UPDATE_CMD_QUEUED/STARTING/DOWNLOADING/INSTALLING/VERIFYING/ROLLED_BACK`); `UPDATE_CMD_IN_FLIGHT` / `UPDATE_CMD_TERMINAL` sets; `UPDATE_CMD_STALE_MINUTES = 20`; `PUSH_UPDATE_MIN_VERSION = '1.2.10'`; `update_command_id` fillable; `supportsPushUpdate()`, `isUpdateInFlight()`, `reconcileStaleUpdateCommand()`. |
| `app/Services/Connector/ConnectorClient.php` *(new)* | Signs (HMAC) and POSTs the command to `<home_url>/wp-json/marqira/v1/execute-update` (with `?rest_route=` fallback on 404). Returns `{pushed, state, error, http}`. |
| `app/Http/Controllers/Api/Dashboard/SiteController.php` | `requestUpdate()` reconciles stale commands, generates a `command_id`, attempts push when supported, records push outcome in the audit log, and returns a message reflecting push vs pull. `updateStatus()` reconciles stale before returning. Payload now carries `command_id` + `in_flight`. |
| `app/Http/Controllers/Api/V1/UpdateCommandController.php` | Accepts the granular statuses + `rolled_back`; validates optional `command_id` and ignores acks whose id does not match the in-flight command (backward compatible); sets `completed_at` on any terminal status. |
| `app/Http/Controllers/Api/V1/HeartbeatController.php` | Pull-path command now carries a stable `command_id` (generated once, persisted). |
| `database/migrations/2024_01_07_000001_add_update_command_id_to_sites.php` *(new)* | Adds nullable `update_command_id` (string 64) to `sites`. Reversible. |

### Connector (`wordpress/marqira-connector`, v1.2.10)

| File | Change |
|------|--------|
| `includes/class-marqira-hmac-server.php` *(new)* | Inbound HMAC verifier for pushed requests: validates the 5 `X-MarQira-*` headers, site/kid match, ±300s timestamp, constant-time signature over a canonical string bound to a fixed logical path, and nonce replay protection (transient, 600s TTL). |
| `includes/class-marqira-rest-controller.php` *(new)* | Registers `POST /marqira/v1/execute-update` (HMAC-verified) and `POST /marqira/v1/run-job` (single-use token). Dedups, persists a one-shot job, acknowledges `queued`, and spawns a background worker via a cron event **and** a non-blocking loopback request so work starts immediately. Returns `202 Accepted`. |
| `includes/class-marqira-remote-update.php` | Routed through `execute_command($type,$target,$command_id)` shared by both channels. Adds up-front dedup, job locking with stale-lock recovery by age (`LOCK_MAX_AGE = 900s`), a bounded processed-command list (`PROCESSED_CAP = 50`), and granular `send_ack()` calls at each lifecycle step (now tagged with `command_id`). |
| `marqira-connector.php` | Loads the two new includes, initializes the REST controller, version → `1.2.10`. |

### Dashboard (`apps/dashboard`)

| File | Change |
|------|--------|
| `src/types/index.ts` | `UpdateCommandStatus` extended with the granular + `rolled_back` states; `SiteUpdateCommand` gains `command_id` and `in_flight`. |
| `src/pages/WebsiteDetail.tsx` | New in-flight status set; a **step progress bar** (Queued→…→Completed); tone/label maps for every state; polls every **5s** while in flight (was 15s); shows the distinct push-vs-heartbeat waiting message and the clear failure/timeout message from the API. |

## 4. End-to-end flow

```
Dashboard click
   │
   ▼
POST /api/dashboard/sites/{uuid}/request-update   (SiteController::requestUpdate)
   │  reconcile stale → generate command_id → set update_command_id
   │
   ├─ connector ≥ 1.2.10 ?  ── yes ─▶ ConnectorClient::pushUpdateCommand()
   │                                     │  HMAC-signed POST → site REST
   │                                     ▼
   │                        /wp-json/marqira/v1/execute-update
   │                          Marqira_Hmac_Server::verify()  (headers, ts, nonce, sig)
   │                          dedup → persist one-shot job → ack "queued" (202)
   │                          spawn background worker (cron event + loopback)
   │                                     │
   │                                     ▼
   │                        /wp-json/marqira/v1/run-job  (single-use token)
   │                          execute_command() → acquire lock →
   │                          WP_Upgrader run → send_ack at each step:
   │                          starting → downloading → installing → verifying → completed
   │
   └─ else / push failed ─▶ command stays `pending`
                             delivered on next heartbeat (pull channel, unchanged)

Every send_ack → POST /api/v1/update-command/ack (HMAC) → UpdateCommandController::ack()
   records granular status; ignores acks with no in-flight command or a mismatched command_id.

Dashboard polls GET /update-status every 5s while in_flight, rendering the step bar.
```

## 5. Reliability guarantees

- **Starts without a visitor** — push + non-blocking loopback worker; does not
  depend on organic traffic to fire WP-Cron.
- **Clear failure when it can't start** — if the site is unreachable or rejects
  the push, the API keeps the command `pending`, records the reason in the audit
  log, and the dashboard shows the fallback message (delivery on next heartbeat)
  rather than a fake "success".
- **Never stuck forever** — `reconcileStaleUpdateCommand()` flips any command
  older than **20 minutes** with no terminal ack to `failed` ("Update timed out;
  you can retry"), unblocking a fresh request. Runs on every status poll and
  before each new request.
- **No double-execution** — up-front duplicate-command protection
  (`command_id`), a job lock with **stale-lock recovery by age** (15 min), and a
  **single-use job token** for the loopback worker.
- **Ack integrity** — acks are HMAC-verified and ignored if they reference a
  command that is not the one currently in flight (`command_id` mismatch),
  preventing a late ack from a superseded command from corrupting state.
- **No optimistic UI** — the dashboard only shows states the site actually
  reported; "Completed" appears only after the connector confirms it.

## 6. Testing performed

> This VM cannot run a live WordPress site or the full Laravel + Postgres +
> Redis stack end-to-end. Everything below was validated with unit/feature
> tests, the connector PHP harness, `php -l`, and the dashboard build. Items
> that require a real site are listed in §7.

- **API — Pest:** full suite **264 passed** (898 assertions). New:
  `tests/Feature/Dashboard/SitePushUpdateTest.php` (6 — push accepted→queued with
  signed headers, failed push→pending fallback, old connector→no push, stale→
  failed reconcile, fresh not reconciled, stale unblocks new request); appended 4
  ack tests to `tests/Feature/RemoteUpdateCommandTest.php` (granular states
  non-terminal, `rolled_back` terminal, matching/mismatched `command_id`).
- **Connector — `php tests/run.php`:** **105 passed / 4 pre-existing harness
  failures** (unrelated: missing `wp-admin/includes/plugin.php` and
  `get_permalink` in the stub harness — present before Phase A). New:
  `tests/test-hmac-server.php` (10 — valid verify, tampered body, wrong site,
  wrong kid, expired ts, path binding, replay first/second, missing headers) and
  `tests/test-remote-update-dedup.php` (10 — dedup + lock/stale-recovery).
- **Static:** `php -l` clean on every changed PHP file.
- **Dashboard:** `tsc --noEmit` clean; `npm run build` succeeds.

## 7. Requires live-site verification (cannot be done in this VM)

The following behaviors are implemented and unit/feature-tested but must be
confirmed against at least one real WordPress site (ideally WP 6.x and WP 7.1):

1. Push request reaches the site's REST endpoint through the site's real web
   server / firewall / Cloudflare, and the HMAC verifies end-to-end.
2. The non-blocking loopback worker actually spawns and runs the upgrader on the
   host (some hosts block loopback requests; the cron-event path is the backup).
3. `WP_Upgrader` completes real plugin / theme / core / connector-self updates
   and each granular ack (downloading/installing/verifying) is emitted in order.
4. Stale-lock recovery under a genuinely crashed mid-update process.
5. Behavior on a host with `DISABLE_WP_CRON` / alternate cron.

## 8. Coolify deployment steps

No new environment variables are required. Redis is already required by the API
(nonce manager) — no change there.

1. **Pull `main`** in the API and dashboard services (or trigger redeploy).
2. **API — run the new migration** (one new nullable column, safe/reversible):
   ```
   php artisan migrate --force
   ```
   In Coolify: API service → Terminal (or a post-deploy command) →
   `php artisan migrate --force`.
3. **API — clear/rebuild caches** if you cache config/routes:
   ```
   php artisan config:clear && php artisan route:clear
   ```
4. **Dashboard — rebuild** (Coolify runs `npm run build` on deploy; confirm the
   build succeeds — it does locally).
5. **Publish the connector release:** upload
   `releases/marqira-connector-1.2.10.zip` via the dashboard's Plugin Releases
   screen and **activate** it, so sites can self-update to `1.2.10`. Until a site
   is on `1.2.10` it keeps using the pull (heartbeat) channel — no breakage.
6. **No worker/queue change** is needed for Phase A (push is synchronous from the
   request; the background execution happens inside WordPress on the site).

## 9. Rollback

- Revert the deploy to the previous commit.
- The migration is reversible: `php artisan migrate:rollback` drops
  `update_command_id`. The API tolerates its absence (all reads are guarded).
- Sites already upgraded to connector `1.2.10` continue to function; the push
  endpoint simply goes unused if the API no longer calls it.
