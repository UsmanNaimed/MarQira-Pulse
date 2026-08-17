<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('origin_ip_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('event_type', 50); // detected, verified, confidence_changed, manual_override
            $table->string('origin_ip', 45)->nullable();
            $table->string('previous_origin_ip', 45)->nullable();
            $table->string('source', 100)->nullable(); // dns_a, dns_aaaa, server_addr, manual, dns_analysis
            $table->string('confidence', 20)->nullable(); // high, medium, low, unknown
            $table->string('previous_confidence', 20)->nullable();
            $table->boolean('verified')->default(false);
            $table->unsignedBigInteger('performed_by')->nullable(); // user who performed verification
            $table->json('metadata')->nullable(); // DNS records, analysis details, etc.
            $table->text('notes')->nullable(); // optional notes from manual verification
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->nullable();

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('performed_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['site_id', 'recorded_at']);
            $table->index(['organization_id', 'recorded_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('origin_ip_history');
    }
};
