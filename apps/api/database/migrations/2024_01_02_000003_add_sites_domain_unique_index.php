<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enforce "one active site per (organization, normalized domain)" with a
 * PARTIAL unique index (only rows that are not revoked participate). This lets
 * a revoked/historical record and a live record for the same domain coexist,
 * while preventing duplicate *active* dashboard rows (see §10).
 *
 * SAFE MIGRATION STRATEGY (non-destructive):
 *  1. Before creating the index, existing active duplicates are resolved by
 *     SOFT-revoking all but the "best" record per (org, normalized domain).
 *     The best record is the one with the most recent heartbeat (falling back
 *     to the newest row). Revoke is reversible — no rows are deleted — so this
 *     is safe to run against production and the accompanying
 *     `marqira:deduplicate-sites --dry-run` command lets you preview it.
 *  2. The partial unique index is then created cleanly.
 *
 * Both PostgreSQL and SQLite support partial (`WHERE`) unique indexes, so the
 * same SQL works in production and in the test suite.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->softRevokeExistingDuplicates();

        $driver = DB::connection()->getDriverName();

        // Partial unique index: only non-revoked rows are constrained.
        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX sites_org_domain_active_unique '
                .'ON sites (organization_id, domain_normalized) '
                .'WHERE revoked_at IS NULL AND domain_normalized IS NOT NULL'
            );
        } else {
            // Fallback for drivers without partial-index support: a plain unique
            // index across the pair. Revoked rows would need domain_normalized
            // cleared to reuse a domain; documented for MySQL deployments.
            DB::statement(
                'CREATE UNIQUE INDEX sites_org_domain_active_unique '
                .'ON sites (organization_id, domain_normalized)'
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sites_org_domain_active_unique');
    }

    /**
     * Soft-revoke duplicate active rows, keeping the best record per group.
     */
    private function softRevokeExistingDuplicates(): void
    {
        $groups = DB::table('sites')
            ->select('organization_id', 'domain_normalized')
            ->whereNull('revoked_at')
            ->whereNotNull('domain_normalized')
            ->groupBy('organization_id', 'domain_normalized')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $rows = DB::table('sites')
                ->where('organization_id', $group->organization_id)
                ->where('domain_normalized', $group->domain_normalized)
                ->whereNull('revoked_at')
                ->orderByRaw('last_heartbeat_at IS NULL')      // non-null first
                ->orderByDesc('last_heartbeat_at')             // most recent heartbeat
                ->orderByDesc('id')                            // newest as tiebreak
                ->get(['id']);

            // Keep the first (best) row; revoke the rest.
            $keep = $rows->shift();

            foreach ($rows as $row) {
                DB::table('sites')->where('id', $row->id)->update([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
