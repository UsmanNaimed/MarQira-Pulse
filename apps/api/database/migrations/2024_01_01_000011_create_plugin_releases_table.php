<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_releases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('version', 20); // e.g., "1.2.0"
            $table->text('changelog')->nullable();
            $table->string('download_url', 500); // URL to .zip file
            $table->string('file_hash', 64)->nullable(); // SHA256 hash for integrity
            $table->unsignedBigInteger('file_size')->nullable(); // bytes
            $table->string('requires_wp', 20)->nullable(); // e.g., "6.0"
            $table->string('requires_php', 20)->nullable(); // e.g., "7.4"
            $table->string('tested_up_to', 20)->nullable(); // e.g., "6.4"
            $table->boolean('is_active')->default(false); // only one active at a time
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('released_by')->nullable(); // user who published
            $table->timestamps();

            $table->foreign('released_by')->references('id')->on('users')->nullOnDelete();
            
            $table->unique('version');
            $table->index('is_active');
            $table->index('released_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_releases');
    }
};
