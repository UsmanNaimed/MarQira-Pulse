<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Check for sites with stale heartbeats and mark them offline.
 *
 * Runs every 5 minutes via scheduler.
 */
class CheckStaleSitesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'marqira:check-stale-sites';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark sites offline if heartbeat is stale';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $thresholdMinutes = config('marqira.heartbeat.offline_threshold_minutes', 30);
        $threshold = now()->subMinutes($thresholdMinutes);

        // Find sites that are not already offline and have stale heartbeats
        $staleSites = Site::where('status', '!=', 'offline')
            ->where(function ($query) use ($threshold) {
                $query->where('last_heartbeat_at', '<', $threshold)
                    ->orWhereNull('last_heartbeat_at');
            })
            ->get();

        if ($staleSites->isEmpty()) {
            $this->info('No stale sites found.');
            return self::SUCCESS;
        }

        $count = 0;

        foreach ($staleSites as $site) {
            $site->update(['status' => 'offline']);

            // Log to audit trail
            AuditLog::record([
                'organization_id' => $site->organization_id,
                'actor_type' => 'system',
                'event' => 'site_marked_offline',
                'subject_type' => 'site',
                'subject_id' => $site->id,
                'subject_uuid' => $site->uuid,
                'metadata' => [
                    'domain' => $site->domain,
                    'last_heartbeat_at' => $site->last_heartbeat_at?->toIso8601String(),
                    'threshold_minutes' => $thresholdMinutes,
                ],
            ]);

            $count++;
        }

        $this->info("Marked {$count} site(s) offline.");

        return self::SUCCESS;
    }
}
