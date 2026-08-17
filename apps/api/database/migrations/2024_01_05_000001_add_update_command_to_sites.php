<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a remote "update this site now" command channel to sites.
 *
 * The dashboard queues an update command on a single site (status = pending).
 * On the site's next heartbeat the API hands the command to the connector and
 * flips the status to dispatched. The connector (v1.2.2+) runs the WordPress
 * plugin upgrader and reports back via the HMAC-authenticated ack endpoint,
 * which sets completed or failed. Older connectors simply ignore the command
 * key, so requesting an update on a <1.2.2 site is a no-op that never breaks
 * the heartbeat.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // null | pending | dispatched | in_progress | completed | failed
            $table->string('update_command_status', 20)->nullable()->after('plugin_version');
            $table->string('update_command_target_version', 20)->nullable()->after('update_command_status');
            $table->timestamp('update_command_requested_at')->nullable()->after('update_command_target_version');
            $table->unsignedBigInteger('update_command_requested_by')->nullable()->after('update_command_requested_at');
            $table->timestamp('update_command_dispatched_at')->nullable()->after('update_command_requested_by');
            $table->timestamp('update_command_completed_at')->nullable()->after('update_command_dispatched_at');
            $table->text('update_command_message')->nullable()->after('update_command_completed_at');

            $table->index('update_command_status');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['update_command_status']);
            $table->dropColumn([
                'update_command_status',
                'update_command_target_version',
                'update_command_requested_at',
                'update_command_requested_by',
                'update_command_dispatched_at',
                'update_command_completed_at',
                'update_command_message',
            ]);
        });
    }
};
