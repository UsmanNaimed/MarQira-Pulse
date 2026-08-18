<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Active-verification (independent uptime probe) tracking for sites.
 *
 * Root-cause fix for false-offline alerts: a stale heartbeat no longer flips a
 * site offline by itself. Instead the monitor probes the real website over
 * HTTP(S) and only declares an outage after repeated CONFIRMED failures. These
 * columns hold the per-site state machine for that verification:
 *
 *   - consecutive_check_failures:  confirmed-down probes in a row (resets on any
 *                                  successful probe OR any real heartbeat).
 *   - consecutive_check_successes: successful probes in a row while offline
 *                                  (drives recovery; resets on any failure).
 *   - last_active_check_at:        when the last probe ran.
 *   - last_active_check_status:    up | down | inconclusive.
 *   - last_active_check_reason:    short machine reason (e.g. http_5xx, dns,
 *                                  timeout, tls, connection, ok_2xx).
 *   - last_active_check_http_code: HTTP status observed (null when no response).
 *   - last_active_check_latency_ms:round-trip time of the last probe.
 *
 * Purely additive, nullable/defaulted — safe on a live table, no backfill
 * required, and fully reversible.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedInteger('consecutive_check_failures')->default(0)->after('offline_alert_count');
            $table->unsignedInteger('consecutive_check_successes')->default(0)->after('consecutive_check_failures');
            $table->timestamp('last_active_check_at')->nullable()->after('consecutive_check_successes');
            $table->string('last_active_check_status', 20)->nullable()->after('last_active_check_at');
            $table->string('last_active_check_reason', 255)->nullable()->after('last_active_check_status');
            $table->unsignedSmallInteger('last_active_check_http_code')->nullable()->after('last_active_check_reason');
            $table->unsignedInteger('last_active_check_latency_ms')->nullable()->after('last_active_check_http_code');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'consecutive_check_failures',
                'consecutive_check_successes',
                'last_active_check_at',
                'last_active_check_status',
                'last_active_check_reason',
                'last_active_check_http_code',
                'last_active_check_latency_ms',
            ]);
        });
    }
};
