<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account management fields (§4/§5/§9): a per-user website limit, last-login
 * tracking for the Owner's Users dashboard, and a future-proofing `plan`
 * column. All nullable/additive so existing rows keep working; a null
 * website_limit means "unlimited" (the Owner is always unlimited).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Null = unlimited. Enforced only when set and the user is a Subscriber.
            $table->unsignedInteger('website_limit')->nullable()->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('website_limit');
            // Reserved for future subscription tiers; no billing logic hangs off it yet.
            $table->string('plan')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['website_limit', 'last_login_at', 'plan']);
        });
    }
};
