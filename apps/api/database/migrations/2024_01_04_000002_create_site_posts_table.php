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
        Schema::create('site_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('organization_id')->index();
            $table->timestamp('snapshot_at')->nullable()->comment('When this post snapshot was captured');
            $table->unsignedBigInteger('wp_post_id')->comment('WordPress post ID on the remote site');
            $table->string('post_type', 20)->default('post')->comment('post, page, or custom post type');
            $table->string('post_status', 20)->nullable()->comment('publish, draft, pending, etc.');
            $table->text('post_title')->nullable();
            $table->timestamp('post_date')->nullable()->comment('When the post was published in WordPress');
            $table->timestamp('post_modified')->nullable()->comment('Last edit time in WordPress');
            $table->unsignedBigInteger('post_author_id')->nullable()->comment('WP user ID who authored the post');
            $table->string('post_author_name', 250)->nullable()->comment('Display name of the author');
            $table->text('guid')->nullable()->comment('WordPress GUID (permalink)');
            $table->jsonb('metadata')->nullable()->comment('Additional post metadata (categories, tags, etc.)');
            $table->timestamp('created_at')->nullable()->comment('When we received this snapshot');

            // Composite indexes for time-series queries
            $table->index(['site_id', 'snapshot_at']);
            $table->index(['organization_id', 'snapshot_at']);
            // Index for deduplication and linking snapshots of the same WP post
            $table->index(['site_id', 'wp_post_id']);
            // Index for filtering by post type/status
            $table->index(['site_id', 'post_type', 'post_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_posts');
    }
};
