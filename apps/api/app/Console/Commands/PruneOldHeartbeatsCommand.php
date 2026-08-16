<?php

namespace App\Console\Commands;

use App\Models\SiteHeartbeat;
use Illuminate\Console\Command;

/**
 * Prune old heartbeat records to manage database size.
 *
 * Runs daily via scheduler.
 */
class PruneOldHeartbeatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'marqira:prune-old-heartbeats';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete heartbeat records older than retention period';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $retentionDays = config('marqira.log.heartbeat_retention_days', 30);
        $cutoff = now()->subDays($retentionDays);

        $this->info("Pruning heartbeats older than {$cutoff->toDateString()}...");

        $deleted = SiteHeartbeat::where('received_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} old heartbeat record(s).");

        return self::SUCCESS;
    }
}
