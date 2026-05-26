<?php

namespace App\Console\Commands;

use App\Models\AccountSnapshot;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneAccountSnapshotsCommand extends Command
{
    protected $signature = 'snapshots:prune
                            {--keep-days=30 : Snapshots newer than this are kept at full 10s granularity}
                            {--downsample-after=2 : Snapshots older than N days are kept at 1-per-minute (downsampled)}';

    protected $description = 'Prune + downsample account_snapshots to keep the table fast. Run nightly.';

    public function handle(): int
    {
        $keepDays           = (int) $this->option('keep-days');
        $downsampleAfter    = (int) $this->option('downsample-after');

        $hardCutoff         = Carbon::now()->subDays($keepDays)->utc();
        $downsampleCutoff   = Carbon::now()->subDays($downsampleAfter)->utc();

        // 1. Hard delete: anything older than --keep-days is gone
        $deleted = AccountSnapshot::where('recorded_at', '<', $hardCutoff)->delete();
        $this->info("Deleted {$deleted} snapshots older than {$keepDays} days.");

        // 2. Downsample: keep only ONE snapshot per minute, per account, for
        //    rows between (downsampleCutoff, hardCutoff). The "newest per minute"
        //    survives; the rest are dropped.
        $downsampled = DB::statement(
            "DELETE s1 FROM account_snapshots s1
             INNER JOIN account_snapshots s2
                 ON s1.mt5_account_id = s2.mt5_account_id
                AND DATE_FORMAT(s1.recorded_at, '%Y-%m-%d %H:%i') =
                    DATE_FORMAT(s2.recorded_at, '%Y-%m-%d %H:%i')
                AND s1.id < s2.id
             WHERE s1.recorded_at <  ?
               AND s1.recorded_at >= ?",
            [$downsampleCutoff, $hardCutoff],
        );

        $remaining = AccountSnapshot::count();
        $this->info("Downsampled middle range to 1-per-minute. Total rows now: " . number_format($remaining));

        return Command::SUCCESS;
    }
}
