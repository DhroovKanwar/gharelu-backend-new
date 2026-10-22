<?php

namespace App\Console\Commands;

use App\Models\AnalyticsEvent;
use Illuminate\Console\Command;

class PruneAnalytics extends Command
{
    protected $signature = 'analytics:prune {--days=90 : keep events newer than this many days}';

    protected $description = 'Delete storefront analytics events older than the retention period.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        $deleted = 0;
        do {
            // In batches so a large backlog never locks the table for long.
            $batch = AnalyticsEvent::where('created_at', '<', now()->subDays($days))->limit(5000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("Deleted {$deleted} event(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
