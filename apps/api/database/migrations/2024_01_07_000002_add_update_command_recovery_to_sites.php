<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Latest critical-error protection & automatic recovery report for the site's
     * most recent update command. Populated by the connector (>= 1.2.11) on the
     * terminal ack. Describes whether the update was blocked because the site was
     * already in a critical state, whether it was automatically rolled back after
     * a post-update critical error, and the post-action health summary. Stored as
     * JSON so the dashboard can render an honest recovery banner.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('update_command_recovery')
                ->nullable()
                ->after('update_command_message');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('update_command_recovery');
        });
    }
};
