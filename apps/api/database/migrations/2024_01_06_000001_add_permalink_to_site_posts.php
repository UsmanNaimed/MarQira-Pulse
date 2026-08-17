<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a `permalink` column to site_posts.
     *
     * The connector sends the resolved public permalink for published posts
     * (pretty URL) and an internal (?p=ID) URL for drafts/scheduled. This is
     * distinct from `guid`, which is the raw WordPress GUID (often the ugly
     * ?p= form even for published posts) and must never change once set.
     */
    public function up(): void
    {
        Schema::table('site_posts', function (Blueprint $table) {
            $table->text('permalink')->nullable()->after('guid')
                ->comment('Resolved URL: public permalink for published, internal for drafts/scheduled');
        });
    }

    public function down(): void
    {
        Schema::table('site_posts', function (Blueprint $table) {
            $table->dropColumn('permalink');
        });
    }
};
