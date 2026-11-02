<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

/**
 * The one command the nightly Railway cron service runs (Railway has no Laravel scheduler):
 *  1. analytics:prune  keeps the analytics table small (the old Forge scheduler did this at 03:00)
 *  2. db:backup        uploads today's database dump to R2
 *
 * A failure in one step never stops the next; the exit code is non-zero if any step failed,
 * so Railway marks that run as failed.
 */
class NightlyMaintenance extends Command
{
    protected $signature = 'nightly:run';

    protected $description = 'Nightly housekeeping: prune old analytics events, then back up the database to R2.';

    private const STEPS = ['analytics:prune', 'db:backup'];

    public function handle(): int
    {
        $failed = false;

        foreach (self::STEPS as $step) {
            $this->line("== {$step}");

            try {
                if ($this->call($step) !== self::SUCCESS) {
                    $failed = true;
                }
            } catch (Throwable $e) {
                $this->error("{$step} crashed: ".$e->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
