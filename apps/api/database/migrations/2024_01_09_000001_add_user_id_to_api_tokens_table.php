<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bind every API token to the user it acts as (§12/§13).
 *
 * API tokens were previously scoped only to an organization. In a multi-user
 * organization that means a Subscriber's token could, once a token-authenticated
 * API exists, read EVERY site in the organization — including the Owner's and
 * other Subscribers' websites and analytics. That is a tenant-isolation
 * violation.
 *
 * We add an explicit `user_id`: the token authenticates AS that user and is
 * therefore constrained by exactly the same `visibleTo` authorization boundary
 * as the dashboard session for that user. Existing tokens are backfilled from
 * `created_by` (the user who created them).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('organization_id');
        });

        // Backfill: an existing token acts as the user who created it.
        DB::table('api_tokens')->whereNull('user_id')->update([
            'user_id' => DB::raw('created_by'),
        ]);

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
