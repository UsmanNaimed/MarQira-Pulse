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
        // NOTE: with active verification enabled (see `active_check` below) this
        // is used ONLY by the legacy fallback path (active_check disabled) as the
        // "stale => immediately offline" gate. When active verification is on the
        // platform no longer waits this long to react — see
        // `probe_interval_minutes` and the reliability contract below.
        'offline_threshold_minutes' => 30,

        /*
        |----------------------------------------------------------------------
        | Liveness reliability contract — the guaranteed verification cadence
        |----------------------------------------------------------------------
        | THE PLATFORM'S MONITORING GUARANTEE. This is how often, at most, every
        | active site is INDEPENDENTLY verified to be alive by MarQira itself —
        | driven by OUR server-side scheduler, NOT by the customer's WP-Cron.
        |
        | Why this exists: heartbeats are push-based and fire from WP-Cron, which
        | only runs when a site gets traffic. On an idle / low-traffic site the
        | connector's own beats arrive at irregular, sometimes 10-50 minute gaps
        | no matter what interval it is configured for. If liveness depended on
        | those beats alone, `last_seen_at` could silently drift far past the
        | interval we advertise — making the "monitored every N minutes" claim
        | untrue. The active probe removes that dependency.
        |
        | THE CONTRACT (with active_check enabled, the default):
        |   - Every active site is verified at least once per this many minutes,
        |     using WHICHEVER signal is fresher: a real heartbeat OR an active
        |     server-initiated HTTP probe. A site with a fresh heartbeat is not
        |     re-probed (already verified + telemetry-rich); a site whose last
        |     verification is older than this window is probed on the next tick.
        |   - `last_seen_at` therefore reflects a VERIFIED liveness event and, for
        |     any reachable site, can never fall further behind than this window
        |     plus one scheduler tick (~1 min). It is never derived from an open
        |     connection or from trusting silence.
        |   - A verified-unreachable site escalates to OFFLINE only after
        |     `active_check.failure_threshold` CONSECUTIVE confirmed failures
        |     (batch-guarded), and recovers on any real heartbeat or
        |     `active_check.recovery_threshold` consecutive successful probes.
        |
        | This is the single seam for future USER-CONFIGURABLE per-plan/per-site
        | intervals: expose a column/setting and pass it here instead of the
        | global default. Keep it >= the scheduler tick (every minute) so at
        | least one probe opportunity falls inside every window; the scheduler in
        | routes/console.php runs every minute and self-throttles per site so a
        | healthy site is probed at most once per window.
        |
        | REMAINING LIMITATION (documented, not a platform gap): the connector's
        | OWN push cadence on a zero-traffic site still depends on WP-Cron / a
        | real server cron hitting wp-cron.php. That only affects heartbeat
        | telemetry freshness — the platform's liveness/last-seen guarantee no
        | longer depends on it because the active probe backstops every site.
        */
        'probe_interval_minutes' => max(1, (int) env('MARQIRA_PROBE_INTERVAL_MINUTES', 3)),

        /*
        |----------------------------------------------------------------------
        | Active verification (independent uptime probe)
        |----------------------------------------------------------------------
        | ROOT-CAUSE FIX for false-offline alerts. Heartbeats are push-based:
        | the WordPress connector fires them from WP-Cron, which only runs when
        | the site receives traffic. An idle / free-tier / cron-disabled site,
        | a connector or plugin error, an outbound-firewall block, or even a
        | problem on OUR monitoring worker all make heartbeats stop arriving —
        | while the website itself is perfectly reachable. Inferring "offline"
        | from heartbeat silence alone therefore produces false outages.
        |
        | When a heartbeat goes stale we now independently probe the real site
        | over HTTP(S) from the monitoring server before changing its state:
        |   - Probe UP  -> the website is reachable; keep/return it ONLINE even
        |                  though it is quiet (this kills the false positive).
        |   - Probe DOWN-> only after `failure_threshold` CONSECUTIVE confirmed
        |                  failures do we declare OFFLINE and alert.
        |   - Recovery -> an offline site needs `recovery_threshold` consecutive
        |                  successful probes (or any real heartbeat) to return
        |                  ONLINE, preventing flapping.
        | A run in which a large share of probed sites fail with network-level
        | errors is treated as a monitoring-side problem and makes NO offline
        | transitions (see the batch guard in CheckStaleSitesCommand).
        */
        'active_check' => [
            // Master switch. When false the monitor falls back to the legacy
            // "stale heartbeat => immediately offline" behavior.
            'enabled' => filter_var(env('MARQIRA_ACTIVE_CHECK_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

            // Total request timeout and TCP connect timeout (seconds). Generous
            // enough to let a sleeping/free-tier host finish a cold start.
            'timeout_seconds' => (int) env('MARQIRA_ACTIVE_CHECK_TIMEOUT', 15),
            'connect_timeout_seconds' => (int) env('MARQIRA_ACTIVE_CHECK_CONNECT_TIMEOUT', 10),

            // Extra in-probe retries for a single check (absorbs a one-off blip
            // and gives a cold-starting site a second chance in the same run).
            'retries' => (int) env('MARQIRA_ACTIVE_CHECK_RETRIES', 1),
            'retry_backoff_ms' => (int) env('MARQIRA_ACTIVE_CHECK_RETRY_BACKOFF_MS', 750),

            // Consecutive confirmed-down probes before a site is declared
            // OFFLINE. With the every-minute monitor this adds ~N minutes of
            // confirmation on top of the stale trigger — fast, but immune to a
            // single transient failure.
            'failure_threshold' => (int) env('MARQIRA_ACTIVE_CHECK_FAILURE_THRESHOLD', 3),

            // Consecutive successful probes before an OFFLINE site is returned
            // ONLINE (a real heartbeat still recovers it immediately).
            'recovery_threshold' => (int) env('MARQIRA_ACTIVE_CHECK_RECOVERY_THRESHOLD', 2),

            // Batch worker-network guard: if at least this many probed sites AND
            // at least this fraction of them fail in ONE run with network-level
            // errors (DNS/connect/timeout), the run is treated as a
            // monitoring-side problem and performs NO offline transitions.
            'batch_guard_min_sites' => (int) env('MARQIRA_ACTIVE_CHECK_BATCH_GUARD_MIN', 3),
            'batch_guard_failure_ratio' => (float) env('MARQIRA_ACTIVE_CHECK_BATCH_GUARD_RATIO', 0.75),

            // Identify our monitor politely in access logs.
            'user_agent' => env('MARQIRA_ACTIVE_CHECK_USER_AGENT', 'MarQira-Pulse-Monitor/1.0 (+uptime)'),
        ],
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
