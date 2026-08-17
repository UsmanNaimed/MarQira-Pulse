<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds storage_path so uploaded plugin zips can be located on the
     * `releases` disk and streamed by the private update server.
     */
    public function up(): void
    {
        Schema::table('plugin_releases', function (Blueprint $table) {
            $table->string('storage_path', 500)->nullable()->after('download_url');
        });
    }

    public function down(): void
    {
        Schema::table('plugin_releases', function (Blueprint $table) {
            $table->dropColumn('storage_path');
        });
    }
};
