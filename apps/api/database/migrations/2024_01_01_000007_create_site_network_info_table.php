<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_network_info', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('organization_id');
            $table->timestamp('recorded_at')->nullable();
            $table->integer('network_sites_count')->nullable();
            $table->jsonb('network_data')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();

            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_network_info');
    }
};
