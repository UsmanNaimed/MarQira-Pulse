<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Introduce the platform-level role system (Owner / Subscriber) and an
 * activation flag.
 *
 * This is additive and non-destructive:
 *  - `platform_role` defaults to 'subscriber' so existing behaviour is safe.
 *  - Existing organization "owner" members are promoted to platform Owner so
 *    current administrators keep full visibility after deploy.
 *  - The designated primary Owner (ozman.best@gmail.com) is always promoted
 *    when that account already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('platform_role', 20)->default('subscriber')->after('email');
            $table->boolean('is_active')->default(true)->after('platform_role');
            $table->index('platform_role');
        });

        // Promote existing organization owners to platform Owner so nothing
        // loses visibility on upgrade.
        $ownerUserIds = DB::table('organization_memberships')
            ->where('role', 'owner')
            ->pluck('user_id')
            ->unique()
            ->all();

        if (! empty($ownerUserIds)) {
            DB::table('users')
                ->whereIn('id', $ownerUserIds)
                ->update(['platform_role' => 'owner']);
        }

        // Always ensure the designated primary Owner is an Owner if present.
        DB::table('users')
            ->where('email', 'ozman.best@gmail.com')
            ->update(['platform_role' => 'owner']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['platform_role']);
            $table->dropColumn(['platform_role', 'is_active']);
        });
    }
};
