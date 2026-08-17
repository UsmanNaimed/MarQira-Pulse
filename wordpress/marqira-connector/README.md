# MarQira Connector

Version 1.1.3 · Requires WordPress 5.6+ · Requires PHP 7.4+

MarQira Connector links your WordPress site to **MarQira Pulse** for centralised
monitoring and automation. Its primary job in Phase 1 is to **restrict Application
Password authentication to approved MarQira infrastructure IPs**, without affecting
normal wp-admin login, cookie authentication, or the public REST API.

## Features

- **Application Password IP allow-list** via the `wp_authenticate_application_password_errors` action hook.
- **Cloudflare-safe client IP detection** — resolves the real client IP from `CF-Connecting-IP` only when `REMOTE_ADDR` is a known Cloudflare proxy; never trusts arbitrary `X-Forwarded-For`.
- **IPv4 / IPv6 / CIDR** matching using `inet_pton` binary comparison.
- **Admin settings page** (Settings → MarQira Connector) with diagnostics, a "Test Current IP" tool, and a Recent Activity log.
- **Bounded activity log** stored in a dedicated database table (`{prefix}marqira_log`); capped at 500 entries. Never logs passwords, secrets, or tokens.

## Settings

- `protection_enabled` (bool, default `true`)
- `allowed_ips` (array, default `['187.77.136.105']`)

Stored in the `marqira_connector_settings` option. Uninstalling the plugin removes all plugin options **and** the `{prefix}marqira_log` database table.

## Installation

1. Upload the `marqira-connector` folder to `wp-content/plugins/`.
2. Activate **MarQira Connector** from the Plugins screen.
3. Go to **Settings → MarQira Connector** to review diagnostics and configure allowed IPs.

## Changelog

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
