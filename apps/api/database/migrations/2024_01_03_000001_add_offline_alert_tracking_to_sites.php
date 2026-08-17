<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline-alert tracking for professional uptime alerting.
 *
 * These columns let the scheduler drive an alerting state machine per site:
 *   - offline_since:          when the CURRENT offline episode began (null when online)
 *   - last_offline_alert_at:  when the most recent offline alert email was sent
 *   - offline_alert_count:    number of offline alerts sent in the current episode
 *
 * All three are reset (offline_since/last_offline_alert_at → null,
 * offline_alert_count → 0) when the site recovers, so each offline episode gets
 * its own initial + repeated alerts and a single recovery alert.
 *
 * Purely additive and nullable/defaulted — safe on a live table, no backfill
 * required, and fully reversible.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('offline_since')->nullable()->after('last_seen_at');
            $table->timestamp('last_offline_alert_at')->nullable()->after('offline_since');
            $table->unsignedInteger('offline_alert_count')->default(0)->after('last_offline_alert_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['offline_since', 'last_offline_alert_at', 'offline_alert_count']);
        });
    }
};
