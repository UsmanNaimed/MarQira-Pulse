<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('site_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('organization_id')->index();
            $table->timestamp('snapshot_at')->nullable()->comment('When this user snapshot was captured');
            $table->unsignedBigInteger('wp_user_id')->comment('WordPress user ID on the remote site');
            $table->string('user_login', 60)->comment('WordPress username');
            $table->string('user_email', 100)->nullable()->comment('User email (may be redacted for privacy)');
            $table->string('display_name', 250)->nullable();
            $table->timestamp('user_registered')->nullable()->comment('When the user registered in WordPress');
            $table->jsonb('roles')->nullable()->comment('WordPress roles array (e.g. ["administrator", "editor"])');
            $table->timestamp('last_login_at')->nullable()->comment('Last known login time (if tracked by the connector)');
            $table->jsonb('metadata')->nullable()->comment('Additional user metadata');
            $table->timestamp('created_at')->nullable()->comment('When we received this snapshot');

            // Composite index for time-series queries
            $table->index(['site_id', 'snapshot_at']);
            $table->index(['organization_id', 'snapshot_at']);
            // Index for deduplication and linking snapshots of the same WP user
            $table->index(['site_id', 'wp_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_users');
    }
};
