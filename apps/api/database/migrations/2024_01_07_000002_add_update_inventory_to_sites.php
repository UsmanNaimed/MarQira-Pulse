<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Update inventory (§13): the single authoritative record of what updates each
 * site actually has waiting. Reported by the connector on every heartbeat and
 * consumed by both the per-site Updates tab and the Websites overview warning,
 * so the two never disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('core_update_available')->default(false)->after('is_multisite');
            $table->unsignedInteger('plugin_updates_count')->default(0)->after('core_update_available');
            $table->unsignedInteger('theme_updates_count')->default(0)->after('plugin_updates_count');
            $table->timestamp('updates_checked_at')->nullable()->after('theme_updates_count');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'core_update_available',
                'plugin_updates_count',
                'theme_updates_count',
                'updates_checked_at',
            ]);
        });
    }
};
