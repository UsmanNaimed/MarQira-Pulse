<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deduplicate websites that share the same normalized domain within an
 * organization (§10).
 *
 * A "best" row is kept per (organization_id, domain_normalized) group and the
 * rest are soft-revoked (never hard-deleted), so history is preserved and the
 * partial unique index can be relied upon going forward. The keeper is the row
 * with the most recent heartbeat (ties broken by newest id).
 *
 * Safe by default: run with --dry-run first to preview exactly what would
 * change. Nothing is written unless you run without --dry-run.
 */
class DeduplicateSitesCommand extends Command
{
    protected $signature = 'marqira:deduplicate-sites
                            {--dry-run : Show what would change without writing anything}';

    protected $description = 'Soft-revoke duplicate websites sharing a normalized domain (keeps the most-recently-seen row)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be written.');
        }

        // Group active sites that have a normalized domain by (org, domain).
        $groups = Site::query()
            ->whereNull('revoked_at')
            ->whereNotNull('domain_normalized')
            ->get()
            ->groupBy(fn (Site $s) => $s->organization_id.'|'.$s->domain_normalized)
            ->filter(fn ($rows) => $rows->count() > 1);

        if ($groups->isEmpty()) {
            $this->info('No duplicate websites found.');
            return self::SUCCESS;
        }

        $totalRevoked = 0;

        foreach ($groups as $key => $rows) {
            // Keeper: most recent heartbeat, then newest id.
            $sorted = $rows->sort(function (Site $a, Site $b) {
                $aHb = $a->last_heartbeat_at?->getTimestamp() ?? 0;
                $bHb = $b->last_heartbeat_at?->getTimestamp() ?? 0;
                if ($aHb !== $bHb) {
                    return $bHb <=> $aHb;
                }
                return $b->id <=> $a->id;
            })->values();

            $keeper = $sorted->first();
            $duplicates = $sorted->slice(1);

            [$orgId, $domain] = explode('|', (string) $key, 2);
            $this->line(sprintf(
                'Domain "%s" (org %s): keeping site #%d, revoking %d duplicate(s).',
                $domain,
                $orgId,
                $keeper->id,
                $duplicates->count()
            ));

            foreach ($duplicates as $dup) {
                $this->line("  - would revoke site #{$dup->id} ({$dup->uuid})");

                if ($dryRun) {
                    $totalRevoked++;
                    continue;
                }

                DB::transaction(function () use ($dup, $keeper) {
                    $dup->update([
                        'status' => Site::STATUS_REVOKED,
                        'revoked_at' => now(),
                        'disconnected_at' => now(),
                    ]);

                    AuditLog::record([
                        'organization_id' => $dup->organization_id,
                        'actor_type' => 'system',
                        'event' => 'site.deduplicated',
                        'subject_type' => 'site',
                        'subject_id' => $dup->id,
                        'subject_uuid' => $dup->uuid,
                        'metadata' => [
                            'domain' => $dup->domain,
                            'kept_site_uuid' => $keeper->uuid,
                        ],
                    ]);
                });

                $totalRevoked++;
            }
        }

        if ($dryRun) {
            $this->info("DRY RUN complete — {$totalRevoked} duplicate site(s) would be revoked.");
        } else {
            $this->info("Revoked {$totalRevoked} duplicate site(s).");
        }

        return self::SUCCESS;
    }
}
