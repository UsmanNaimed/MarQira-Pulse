<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ownership backfill (§12): assign any pre-existing site that has no
 * owner_user_id to the platform Owner of its organization, so tenant isolation
 * has a concrete owner to key off. No connector reconnect and no secret changes
 * — we only stamp owner_user_id on rows that are currently null.
 *
 * The Owner is resolved per organization: prefer a member whose users row is
 * platform_role = 'owner', otherwise fall back to the membership marked
 * role = 'owner'. Sites in organizations with no resolvable owner are left
 * untouched (they remain Owner-visible via scopeVisibleTo regardless).
 */
return new class extends Migration
{
    public function up(): void
    {
        $orgIds = DB::table('sites')
            ->whereNull('owner_user_id')
            ->distinct()
            ->pluck('organization_id');

        foreach ($orgIds as $orgId) {
            if ($orgId === null) {
                continue;
            }

            // Prefer a platform Owner who is a member of this organization.
            $ownerId = DB::table('organization_memberships as m')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.organization_id', $orgId)
                ->where('u.platform_role', 'owner')
                ->orderBy('m.created_at')
                ->value('u.id');

            // Fall back to the organization's own "owner" membership row.
            if (! $ownerId) {
                $ownerId = DB::table('organization_memberships')
                    ->where('organization_id', $orgId)
                    ->where('role', 'owner')
                    ->orderBy('created_at')
                    ->value('user_id');
            }

            if (! $ownerId) {
                continue;
            }

            DB::table('sites')
                ->where('organization_id', $orgId)
                ->whereNull('owner_user_id')
                ->update(['owner_user_id' => $ownerId]);
        }
    }

    public function down(): void
    {
        // Non-reversible data backfill; leaving owner_user_id in place is safe.
    }
};
