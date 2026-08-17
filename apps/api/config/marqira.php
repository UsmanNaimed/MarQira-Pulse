<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Secret encryption key
    |--------------------------------------------------------------------------
    | Base64-encoded 32-byte key used by SecretEncryptor (AES-256-GCM) to seal
    | site secrets at rest. Generate with:
    |   php -r "echo base64_encode(random_bytes(32));"
    */
    'secret_key' => env('MARQIRA_SECRET_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Allowed MarQira infrastructure IPs
    |--------------------------------------------------------------------------
    | Comma-separated list of IPs/CIDRs permitted to reach privileged internal
    | endpoints (n8n automation and the plugin control-plane).
    */
    'allowed_ips' => array_filter(array_map('trim', explode(',', env('MARQIRA_ALLOWED_IPS', '187.77.136.105')))),

    /*
    |--------------------------------------------------------------------------
    | Uptime timing — FOUR INDEPENDENT KNOBS
    |--------------------------------------------------------------------------
    | These four settings are deliberately separate; changing one does not
    | require changing the others. They must stay in a sane relationship:
    |
    |  (1) Connector heartbeat cadence — how often each WordPress site beats.
    |      WHERE: the connector plugin, Marqira_Heartbeat::HEARTBEAT_INTERVAL_MINUTES
    |             (2 minutes during testing; 10 minutes in production).
    |      This lives in the plugin, not here, because it runs on the customer site.
    |
    |  (2) Offline / stale threshold — how long without a beat before a site is
    |      considered offline. WHERE: `heartbeat.offline_threshold_minutes` below.
    |      Keep it comfortably larger than the heartbeat cadence so a single
    |      missed beat never flips a healthy site offline.
    |
    |  (3) Stale-site monitor frequency — how often the server checks for stale
    |      sites and sends due alerts. WHERE: routes/console.php
    |      (Schedule::command('marqira:check-stale-sites')->everyMinute()).
    |      Runs every minute so that short repeat intervals can be honored; it is
    |      timestamp-driven and does NOT email every minute.
    |
    |  (4) Repeated-alert frequency — how often a still-offline site re-alerts.
    |      WHERE: `alerts.offline_repeat_minutes` below (2 minutes during testing;
    |      60 minutes in production). This must be >= the monitor frequency (3);
    |      because the monitor runs every minute, any value >= 1 minute works.
    |
    | Rule of thumb: heartbeat cadence (1) < offline threshold (2); monitor
    | frequency (3) <= repeat frequency (4).
    */
    'heartbeat' => [
        // (2) A site is "online" if seen within this window (dashboard display).
        'online_threshold_minutes' => 20,
        // (2) A site is marked offline once its last heartbeat is older than this.
        'offline_threshold_minutes' => 30,
    ],

    'enrollment_token' => [
        'expiry_minutes' => 30,
        'max_per_org_per_hour' => 10,
    ],

    'api_token' => [
        'prefix' => 'mq_live_',
    ],

    'plugin' => [
        // The current connector release. When set, the dashboard flags sites
        // running an older version as "updates available". Phase 7 will source
        // this from the release registry instead of the environment.
        'latest_version' => env('MARQIRA_PLUGIN_LATEST_VERSION', '1.2.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Plugin downloads (downloads.marqira.com)
    |--------------------------------------------------------------------------
    | When an owner uploads a connector zip in the dashboard, the API stores it
    | on the `releases` disk and serves it through the private update server.
    | `base_url` is the public origin download links are built from. Point
    | downloads.marqira.com at the API (or a CDN in front of it) and set
    | MARQIRA_DOWNLOADS_BASE_URL accordingly; otherwise links fall back to the
    | app URL so uploads work out of the box with no extra infrastructure.
    */
    'downloads' => [
        'base_url' => rtrim(env('MARQIRA_DOWNLOADS_BASE_URL', env('APP_URL', 'http://localhost')), '/'),
        // Storage disk holding uploaded plugin release zips.
        'disk' => env('MARQIRA_RELEASES_DISK', 'releases'),
    ],

    'log' => [
        'audit_retention_days' => 365,
        'heartbeat_retention_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Offline / recovery alerting
    |--------------------------------------------------------------------------
    | Professional uptime alerting. When a site's heartbeat goes stale it is
    | marked offline and an alert email is sent; the alert repeats every
    | `offline_repeat_minutes` while the site stays offline, and a single
    | recovery email is sent when it comes back online.
    |
    | Recipients: the site's owner (owner_user_id) plus the platform alert
    | address below. `email` is the platform-wide owner alert inbox — configure
    | it in the environment; NEVER hardcode a real address in code.
    */
    'alerts' => [
        'enabled' => filter_var(env('MARQIRA_ALERTS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        // Platform-wide owner alert recipient (env-driven; may be null).
        'email' => env('MARQIRA_ALERT_EMAIL'),

        // (4) How often a still-offline site re-alerts, in minutes. Independent
        // of the connector heartbeat cadence (1) and the offline detection
        // threshold (2). The stale-site monitor (3) runs every minute and is
        // timestamp-driven, so any value >= 1 is honored exactly — e.g. set
        // MARQIRA_OFFLINE_ALERT_REPEAT_MINUTES=2 during testing to get a repeat
        // alert every 2 minutes, or 60 in production. It never emails more often
        // than this interval regardless of how often the monitor runs.
        'offline_repeat_minutes' => (int) env('MARQIRA_OFFLINE_ALERT_REPEAT_MINUTES', 60),
    ],
];
