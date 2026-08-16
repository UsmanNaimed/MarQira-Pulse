# MarQira Connector

Version 1.0.0 · Requires WordPress 5.6+ · Requires PHP 7.4+

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

### 1.0.0
- Initial release (Phase 1 — Application Password guard + IP allow-list).
