<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distinguishes what kind of maintenance a queued update command performs:
     * 'plugin' (connector self-update, the existing behaviour), 'core'
     * (WordPress core upgrade), or 'plugins' (bulk-update all plugins). Only one
     * maintenance command is ever in flight per site at a time.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('update_command_type', 20)
                ->default('plugin')
                ->after('update_command_status');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('update_command_type');
        });
    }
};
