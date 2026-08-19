<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a nullable `uptime_reset_at` marker to sites.
 *
 * When set, it becomes the floor of the uptime measurement window: SiteUptime
 * treats it like a (later) enrolment instant, so the 7-day uptime recomputes
 * fresh from that moment WITHOUT deleting any heartbeat history (audit-safe).
 * This backs the dashboard's "Clear 7-Day Uptime" action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('uptime_reset_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('uptime_reset_at');
        });
    }
};
