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
        'latest_version' => env('MARQIRA_PLUGIN_LATEST_VERSION'),
    ],

    'log' => [
        'audit_retention_days' => 365,
        'heartbeat_retention_days' => 30,
    ],
];
