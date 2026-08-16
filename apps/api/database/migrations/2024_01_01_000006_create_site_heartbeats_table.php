<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_heartbeats', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('organization_id');
            $table->timestamp('received_at');
            $table->string('wp_version', 20)->nullable();
            $table->string('php_version', 20)->nullable();
            $table->string('plugin_version', 20)->nullable();
            $table->string('server_ip', 45)->nullable();
            $table->string('server_hostname', 255)->nullable();
            $table->string('server_software', 255)->nullable();
            $table->string('origin_ip_candidate', 45)->nullable();
            $table->boolean('is_multisite')->default(false);
            $table->jsonb('payload')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();

            $table->index(['site_id', 'received_at']);
            $table->index(['organization_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_heartbeats');
    }
};
