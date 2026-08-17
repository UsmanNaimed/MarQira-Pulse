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

    'heartbeat' => [
        'online_threshold_minutes' => 20,
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

        // How often a still-offline site re-alerts, in minutes. Independent of
        // the connector heartbeat cadence and the offline detection threshold.
        'offline_repeat_minutes' => (int) env('MARQIRA_OFFLINE_ALERT_REPEAT_MINUTES', 60),
    ],
];
