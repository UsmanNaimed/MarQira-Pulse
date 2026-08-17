<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add explicit per-user site ownership, revocation lifecycle state, and a
 * normalized domain used for duplicate prevention.
 *
 * All columns are additive and nullable, so this migration is non-destructive
 * and safe to run against production. Ownership is backfilled from the
 * enrollment token that created each site; where that is unknown, ownership is
 * left null (the Owner still sees every site regardless of owner_user_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_user_id')->nullable()->after('organization_id');
            $table->string('domain_normalized', 255)->nullable()->after('domain');
            $table->timestamp('revoked_at')->nullable()->after('disconnected_at');
            $table->unsignedBigInteger('revoked_by')->nullable()->after('revoked_at');

            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('revoked_by')->references('id')->on('users')->nullOnDelete();

            $table->index('owner_user_id');
            $table->index('domain_normalized');
            $table->index('revoked_at');
        });

        // Backfill domain_normalized for existing rows.
        foreach (DB::table('sites')->select('id', 'domain')->get() as $row) {
            DB::table('sites')
                ->where('id', $row->id)
                ->update(['domain_normalized' => self::normalizeDomain($row->domain)]);
        }

        // Backfill owner_user_id from the enrollment token that created the site.
        $tokenOwners = DB::table('enrollment_tokens')
            ->whereNotNull('used_by_site_id')
            ->whereNotNull('created_by')
            ->pluck('created_by', 'used_by_site_id');

        foreach ($tokenOwners as $siteId => $createdBy) {
            DB::table('sites')
                ->where('id', $siteId)
                ->whereNull('owner_user_id')
                ->update(['owner_user_id' => $createdBy]);
        }
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropForeign(['owner_user_id']);
            $table->dropForeign(['revoked_by']);
            $table->dropIndex(['owner_user_id']);
            $table->dropIndex(['domain_normalized']);
            $table->dropIndex(['revoked_at']);
            $table->dropColumn(['owner_user_id', 'domain_normalized', 'revoked_at', 'revoked_by']);
        });
    }

    /**
     * Normalize a domain/URL to a bare, comparable host:
     * lowercase, scheme stripped, "www." stripped, path/port stripped.
     */
    private static function normalizeDomain(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(strtolower($value));
        if ($value === '') {
            return null;
        }

        // Strip scheme if a full URL was stored.
        if (str_contains($value, '://')) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        }

        // Drop any path, query or port that slipped through.
        $value = explode('/', $value)[0];
        $value = explode(':', $value)[0];
        $value = preg_replace('/^www\./', '', $value);

        return $value !== '' ? $value : null;
    }
};
