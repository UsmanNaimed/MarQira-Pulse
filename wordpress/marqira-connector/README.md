# MarQira Connector

Version 1.1.1 · Requires WordPress 5.6+ · Requires PHP 7.4+

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
