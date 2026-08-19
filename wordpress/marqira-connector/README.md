# MarQira Pulse (connector)

Version 1.2.8 · Requires WordPress 5.6+ · Requires PHP 7.4+

The MarQira Pulse connector links your WordPress site to **MarQira Pulse** for
centralised monitoring, uptime alerting and secure automation. It keeps the
connection alive across plugin updates (no reconnection required), sends
authenticated heartbeats, self-heals its cron schedule, disconnects itself
cleanly when the site is revoked from the dashboard, and **restricts Application
Password authentication to approved MarQira infrastructure IPs** — without
affecting normal wp-admin login, cookie authentication, or the public REST API.

> The plugin folder, slug, text domain and PHP prefix remain `marqira-connector`
> for update compatibility; only the display name changed to **MarQira Pulse**.

## Features

- **Application Password IP allow-list** via the `wp_authenticate_application_password_errors` action hook.
- **Cloudflare-safe client IP detection** — resolves the real client IP from `CF-Connecting-IP` only when `REMOTE_ADDR` is a known Cloudflare proxy; never trusts arbitrary `X-Forwarded-For`.
- **IPv4 / IPv6 / CIDR** matching using `inet_pton` binary comparison.
- **Admin settings page** (Settings → MarQira Connector) with diagnostics, a "Test Current IP" tool, and a Recent Activity log.
- **Bounded activity log** stored in a dedicated database table (`{prefix}marqira_log`); capped at 500 entries. Never logs passwords, secrets, or tokens.
- **Automatic data collection** — collects WordPress user and post snapshots every 6 hours and ships them to the MarQira API for monitoring and analytics. Self-schedules on enrollment and self-heals after upgrades.
- **WP-CLI commands** — manual data collection via `wp marqira collect-data` and schedule status via `wp marqira schedule-status`.

## Settings

- `protection_enabled` (bool, default `true`)
- `allowed_ips` (array, default `['187.77.136.105']`)

Stored in the `marqira_connector_settings` option. Uninstalling the plugin removes all plugin options **and** the `{prefix}marqira_log` database table.

## Installation

1. Upload the `marqira-connector` folder to `wp-content/plugins/`.
2. Activate **MarQira Pulse** from the Plugins screen.
3. Go to **Settings → MarQira Connector** to review diagnostics and configure allowed IPs.

## Changelog

### 1.2.8
- **Detailed update inventory in the heartbeat.** The heartbeat payload now
  includes a full `updates.items` breakdown alongside the existing counts, so the
  dashboard can show *exactly* what needs updating instead of just how many items
  are pending:
  - `items.core` — the running WordPress core `current` version and the pending
    `new` version (or `new: null` when core is up to date).
  - `items.plugins` — **every** installed plugin with its `name`, `slug` (plugin
    file), `current` version, and `new` version (`null` when up to date).
  - `items.themes` — **every** installed theme with its `name`, `stylesheet`,
    `current` version, `new` version (`null` when up to date), and an `active`
    flag for the current theme.
  Collection runs through the standard `wp-admin` update helpers and degrades
  gracefully — any failure falls back to the plain counts and never breaks a
  beat. Older dashboards ignore the new field; the counts remain unchanged, so
  this release is fully backward compatible.

### 1.2.7
- **Fixed duplicate heartbeats ("pairs" ~1 second apart in the activity log).**
  When an idle site finally received a request, two mechanisms could wake in the
  same cycle and each dispatch a beat: the traffic watchdog (deferred to
  `shutdown`) and the recurring WP-Cron event (run in the wp-cron.php loopback
  WordPress spawns from that same request). The 1.2.6 "unified countdown" only
  gated the *watchdog*; the cron event called `send_heartbeat()` directly and was
  never checked, so both went out. A new **dispatch-level de-duplication guard**
  now stamps a `marqira_heartbeat_last_sent` timestamp immediately before each
  network dispatch. Automatic beats (cron + watchdog) skip if a beat already went
  out within the **dedup window (90 seconds — half the 3-minute interval)**, so
  whichever fires first wins and the second is skipped. The window is always
  narrower than a full cadence gap, so an on-cadence beat is never suppressed.
  Manual ("Send Heartbeat Now") and enrollment beats pass `force = true` and are
  never skipped.
- **Idle-site cadence — how to guarantee a beat every 3 minutes with zero
  traffic.** The watchdog and WP-Cron both need an incoming HTTP request to run.
  On a site with **no visitors and no server-level cron**, nothing wakes WordPress
  between requests, so beats arrive only when a sporadic request happens to land —
  producing the long, irregular gaps seen in the logs. This is a fundamental
  WP-Cron limitation, not a connector bug: no in-WordPress mechanism (WP-Cron,
  Action Scheduler or the watchdog) can run without traffic. To guarantee the
  cadence on idle sites, add a **real server-level cron** that pings WP-Cron, e.g.
  add this line to the server crontab (`crontab -e`):

  ```
  */3 * * * * wget -q -O - "https://YOUR-SITE.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
  ```

  (or `curl -s "https://YOUR-SITE.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1`).
  For best results also set `define( 'DISABLE_WP_CRON', true );` in `wp-config.php`
  so WordPress stops firing cron on page loads and relies solely on the reliable
  server cron. Many managed WordPress hosts already run a system cron like this.
  **Note:** even without a server cron, idle gaps do **not** cause false "offline"
  alerts — the MarQira API independently verifies each site with an active HTTP
  check, so the heartbeat is treated as a "connector is alive" signal, not the sole
  online/offline signal.

### 1.2.6
- **Enforced 3-minute heartbeat cadence "by any means".** The recurring interval
  is now a permanent **3 minutes** (the previous 2-minute value was a temporary
  test cadence). More importantly, the cadence no longer depends on WP-Cron alone.
  A new **traffic-triggered watchdog** runs on every front-end and admin request:
  if 3 minutes have elapsed since the last attempt, it fires a heartbeat — so sites
  where WP-Cron is stalled, disabled (`DISABLE_WP_CRON`) or simply starved of
  traffic still report in on schedule. The watchdog is:
  - **Non-blocking** — the network call is deferred to `shutdown` and flushed after
    the response via `fastcgi_finish_request()` when available, so page speed is
    unaffected.
  - **Stampede-safe** — a short-lived lock plus a persistent last-attempt timestamp
    guarantee at most one beat per interval even under concurrent traffic.
  - **Unified** — the cron event, the enrollment beat, the manual button and the
    watchdog all share one last-attempt countdown, so whichever fires first resets
    the timer for the rest (no duplicate beats).
- **New "Send Heartbeat Now" button.** Settings → MarQira Connector now has a
  manual heartbeat button (next to "Collect Data Now") that sends a beat
  immediately and reports the exact outcome — success, or the specific failure
  reason / HTTP status — so owners can verify the connection without waiting for
  the cadence.
- `Marqira_Heartbeat::send_heartbeat()` now returns a
  `{success, message, status_code}` result (the cron hook simply ignores it).
- Backend online/offline thresholds (20 / 30 minutes) are unchanged; a 3-minute
  cadence stays well under them. Existing sites only need the plugin update — no
  reconnection required.

### 1.2.5
- **Privacy-safe visitor tracking (Phase 8).** Daily unique visitor and pageview
  counting using hashed IP + user agent (rotated daily, no PII stored). Aggregates
  are sent via heartbeat for dashboard analytics — traffic trends, growth indicators,
  and site-by-site visitor charts. Tracking can be disabled via
  `define('MARQIRA_DISABLE_VISITOR_TRACKING', true)` in `wp-config.php`.

### 1.2.4
- **Remote bulk theme updates.** The remote-update handler now also accepts the
  `update_all_themes` command verb, running `Theme_Upgrader::bulk_upgrade` across
  every theme with an available update. Like the core and plugin verbs it reports
  `in_progress`, `completed` or `failed` back to the acknowledgement endpoint.
- **Update inventory reporting.** Every heartbeat now includes an `updates` block
  (`core`, `plugins`, `themes`) summarising the available core, plugin and theme
  updates on the site, so the dashboard can show accurate "updates available"
  indicators and enable each maintenance button only when relevant.

### 1.2.3
- **Remote WordPress core & bulk plugin updates.** The remote-update handler now
  accepts three command verbs from the dashboard — `update_plugin` (self-update,
  unchanged), `update_core` (runs `Core_Upgrader` to update WordPress core) and
  `update_all_plugins` (runs `Plugin_Upgrader::bulk_upgrade` across every plugin
  with an available update). Each command reports `in_progress`, `completed` or
  `failed` back to the update-command acknowledgement endpoint.
- **Post permalink collection.** The data collector now records a `permalink` for
  every post it syncs — the public permalink for published posts and an internal
  `?p=<ID>` preview URL for drafts and scheduled posts — so the dashboard Content
  tab can link straight to the correct destination.

### 1.2.2
- **Remote plugin self-update support.** Added the remote-update handler that lets
  the dashboard trigger an in-place update of the MarQira connector itself from
  the private update server, reporting progress back to MarQira Pulse.

### 1.2.1
- **First updater-enabled release (Phase 7).** Bundles the private plugin update
  client (`includes/class-marqira-updater.php`), which is loaded and initialized
  by the plugin bootstrap and checks the MarQira update server at
  `https://api.marqira.com/api/v1/plugin/` for new versions, integrating with
  WordPress's built-in update mechanism. No behavioral changes to monitoring,
  heartbeats, or Application Password protection — this release simply carries a
  bumped version so existing 1.2.0 installs can be offered an in-place update.

### 1.2.0
- **Renamed** the plugin display name to **MarQira Pulse**. The folder, slug,
  text domain and PHP class prefix stay `marqira-connector` so this is a normal
  in-place update — **existing sites do not need to reconnect**.
- **Added** automatic **self-disconnect on revocation.** When a site is removed
  or disconnected from the dashboard, the API answers the next heartbeat with
  `HTTP 403 {"error":"site_revoked","site_revoked":true}`. The connector now
  detects this, **stops the recurring heartbeat cron and clears its stored
  credentials**, so a revoked site goes quiet immediately instead of repeatedly
  hammering the API with rejected beats. Reconnecting requires a fresh enrollment
  code — exactly the intended behavior. A **plain 403** that is *not* a
  revocation signal (e.g. a transient WAF/permission block) is treated as an
  ordinary failure and never wipes credentials.
- **Confirmed** persistent pairing across updates: credentials live in the
  `marqira_site_credentials` option and are preserved on deactivate/upgrade;
  only uninstall removes them. Combined with the existing cron self-heal, an
  updated site keeps monitoring with no manual action.
- All 1.1.3 behavior (IP normalization / HTTP 422 fix, self-healing scheduling,
  no-duplicate cron guarantees, deactivation cleanup) is preserved. The
  temporary 2-minute test cadence remains in place (single constant
  `Marqira_Heartbeat::HEARTBEAT_INTERVAL_MINUTES` — set to `10` for production).

### 1.1.3
- **Fixed** recurring heartbeats failing with **HTTP 422** (`server_ip` /
  `origin_ip_candidate` "must be a valid IP address"). The immediate heartbeat
  runs inside a real admin request where `$_SERVER['SERVER_ADDR']` is a valid IP,
  but the WP-Cron loopback request on some hosts (notably **LiteSpeed**) has no
  usable `SERVER_ADDR`, so the connector was sending the literal fallback string
  `unknown` — which the API (correctly) rejects.
- **Added** a shared canonical IP normalizer (`Marqira_IP_Utils::sanitize_ip()`)
  used by **both** the immediate and scheduled heartbeats. It trims whitespace,
  takes the first entry of a comma-separated proxy list, strips ports
  (`1.2.3.4:443`), unwraps bracketed IPv6 (`[2001:db8::1]:443`), handles IPv6
  zone ids and IPv4-mapped IPv6, and rejects hostnames and malformed values.
- **Changed** the heartbeat payload to **omit** `server_ip` / `origin_ip_candidate`
  when a valid IP cannot be determined (both are `nullable` in the API contract),
  so a missing server IP no longer fails the entire heartbeat.
- **Added** safe diagnostics: when a present-but-invalid server value is rejected,
  the activity log records which source variable was unusable — **never** the raw
  value, secrets, or HMAC material.
- **Changed** the recurring interval to a **temporary 2-minute test cadence**
  (was 10 minutes) so multiple recurring heartbeats can be verified quickly in
  production. This is controlled by a single constant
  (`Marqira_Heartbeat::HEARTBEAT_INTERVAL_MINUTES`) — set it back to `10` to
  restore the production cadence. Backend online/offline thresholds (20/30 min)
  are intentionally unchanged.
- Self-healing scheduling, no-duplicate guarantees, and deactivation cleanup from
  1.1.2 are all preserved. Existing sites only need the plugin update — no
  reconnection required.

### 1.1.2
- **Fixed** the recurring heartbeat cron never being scheduled on most sites. The
  event was only registered by the plugin *activation* hook, which does **not**
  run on plugin upgrades, so after enrolling, a site would send a single
  immediate heartbeat and then go silent — eventually marked Offline by the
  dashboard.
- **Added** self-healing scheduling: on every normal plugin load the connector
  now re-creates the `marqira_send_heartbeat` event automatically if the site is
  enrolled but the event is missing. Existing installs recover on the next page
  load after updating — **no reconnection required**.
- **Added** cron scheduling on successful enrollment, guarded by
  `wp_next_scheduled()` so duplicate events can never accumulate across loads,
  upgrades, activations, or repeated enrollment.
- The heartbeat interval remains **every 10 minutes** (with 0–60s jitter),
  consistent with the backend's 20-minute online / 30-minute offline thresholds.
  Uses standard WP-Cron — no per-site configuration and no `DISABLE_WP_CRON`
  requirement.

### 1.1.1
- **Fixed** infinite recursion between the Cloudflare range resolver and the config
  fetcher fallback that could exhaust PHP memory on unenrolled sites (added a
  dedicated bundled-ranges accessor used by the fallback path).
- **Security** — replaced unauthenticated AES-256-CBC credential storage with
  authenticated **AES-256-GCM** (versioned payload, random nonce, GCM tag,
  strict base64 validation, fail-closed). Prefers a `MARQIRA_SECRET_KEY` constant
  from `wp-config.php`, falling back to a salt-derived key.
- **Improved** enrollment error handling: transport (timeout/DNS/TLS/refused) and
  HTTP (401/422/429/5xx) failures are now classified into safe diagnostic reasons
  without leaking tokens, secrets, signatures, or raw response bodies.
- **Performance** — added a per-request decrypted-credentials cache and bounded the
  heartbeat failure-response body written to the activity log.

### 1.0.0
- Initial release (Phase 1 — Application Password guard + IP allow-list).
