<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedBigInteger('organization_id');
            $table->string('domain', 255);
            $table->string('home_url', 500)->nullable();
            $table->string('site_url', 500)->nullable();
            $table->string('status', 20)->default('unknown');
            $table->text('site_secret_encrypted')->nullable();
            $table->string('site_secret_kid', 100)->nullable();
            $table->string('wp_version', 20)->nullable();
            $table->string('php_version', 20)->nullable();
            $table->string('plugin_version', 20)->nullable();
            $table->string('server_ip', 45)->nullable();
            $table->string('server_hostname', 255)->nullable();
            $table->string('server_software', 255)->nullable();
            $table->string('origin_ip', 45)->nullable();
            $table->string('origin_ip_source', 100)->nullable();
            $table->string('origin_ip_confidence', 20)->default('unknown');
            $table->boolean('origin_ip_verified')->default(false);
            $table->timestamp('origin_ip_verified_at')->nullable();
            $table->unsignedBigInteger('origin_ip_verified_by')->nullable();
            $table->boolean('is_multisite')->default(false);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('origin_ip_verified_by')->references('id')->on('users')->nullOnDelete();

            $table->index('organization_id');
            $table->index('domain');
            $table->index('status');
            $table->index('last_heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
