<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 8 — Visitor Analytics & Traffic Tracking.
     *
     * This table stores daily aggregated visitor and pageview metrics per site.
     * Data is privacy-safe: no PII, no individual visits — only daily totals sent
     * by the connector. Used for traffic trends, growth indicators, and analytics.
     */
    public function up(): void
    {
        Schema::create('site_visitor_metrics', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_id')
                ->constrained('sites')
                ->onDelete('cascade');

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->onDelete('cascade');

            // The calendar date this metric covers (site's local date when aggregated).
            $table->date('date');

            // Daily aggregated counts (privacy-safe, no individual tracking).
            $table->unsignedInteger('unique_visitors')->default(0);
            $table->unsignedInteger('pageviews')->default(0);

            // When the connector sent/recorded this metric.
            $table->timestamp('recorded_at');

            // Only created_at (append-only metrics, never updated).
            $table->timestamp('created_at')->nullable();

            // Indexes.
            $table->unique(['site_id', 'date']);
            $table->index('organization_id');
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_visitor_metrics');
    }
};
