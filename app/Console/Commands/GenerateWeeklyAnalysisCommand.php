<?php

namespace App\Console\Commands;

use App\Models\ChartRequest;
use App\Models\CurrencyAnalysis;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateWeeklyAnalysisCommand extends Command
{
    protected $signature = 'analysis:generate-weekly
                            {--symbols= : Comma-separated list of symbols to queue (defaults to the 9 majors)}
                            {--user= : User ID to attribute the analyses to}
                            {--timeout=420 : Seconds to wait for each symbol before giving up (default 7 min)}
                            {--poll=5 : Polling interval in seconds when waiting}';

    protected $description = 'Sequentially generate AI analysis for each major FX pair, waiting for each one to complete before starting the next (no queue worker required).';

    /**
     * Default set of pairs (one per base currency on the analysis page).
     */
    private const DEFAULT_SYMBOLS = [
        'AUDUSD', 'USDCAD', 'EURUSD', 'GBPUSD',
        'USDCHF', 'NZDUSD', 'USDSGD', 'USDJPY',
    ];

    public function handle(): int
    {
        // CLI script — let it run as long as it needs to. With sequential
        // mode, 8 symbols at ~60s each = ~8 minutes worst case.
        @set_time_limit(0);

        $symbols = $this->option('symbols')
            ? array_filter(array_map('trim', explode(',', $this->option('symbols'))))
            : self::DEFAULT_SYMBOLS;

        $userId  = $this->option('user') ? (int) $this->option('user') : null;
        $timeout = max(60, (int) $this->option('timeout'));
        $poll    = max(2, (int) $this->option('poll'));

        $now = CarbonImmutable::now('Asia/Singapore');
        $weekStart = $now->startOfWeek()->toDateString();
        $weekEnd   = $now->endOfWeek()->toDateString();

        $this->info("Weekly analysis — sequential mode");
        $this->line("  Symbols: " . implode(', ', $symbols));
        $this->line("  Week:    {$weekStart} → {$weekEnd}");
        $this->line("  Timeout per symbol: {$timeout}s   Poll: {$poll}s");
        $this->newLine();

        $stats = ['completed' => 0, 'failed' => 0, 'skipped' => 0, 'timeout' => 0];
        $overallStart = microtime(true);

        foreach ($symbols as $i => $symbol) {
            $symbol = strtoupper($symbol);
            $prefix = sprintf('[%d/%d %s]', $i + 1, count($symbols), $symbol);

            // Skip if a completed analysis already exists for this week
            $existing = CurrencyAnalysis::where('symbol', $symbol)
                ->where('week_start', $weekStart)
                ->where('status', 'completed')
                ->first();
            if ($existing) {
                $this->line("{$prefix} ↩ already completed this week (#{$existing->id})");
                $stats['skipped']++;
                continue;
            }

            // Create analysis + chart request (status=pending)
            $analysis = CurrencyAnalysis::create([
                'symbol'     => $symbol,
                'week_start' => $weekStart,
                'week_end'   => $weekEnd,
                'status'     => 'pending',
                'user_id'    => $userId,
            ]);

            ChartRequest::create([
                'currency_analysis_id' => $analysis->id,
                'symbol' => $symbol,
                'status' => 'pending',
            ]);

            $this->info("{$prefix} → queued (analysis #{$analysis->id})");
            $this->output->write("       waiting for EA → ");

            // Poll until analysis is completed or failed (or we time out)
            $startedAt = microtime(true);
            $finalStatus = null;

            while (true) {
                sleep($poll);

                $fresh = $analysis->fresh();
                if (! $fresh) {
                    $finalStatus = 'gone';
                    break;
                }

                if ($fresh->status === 'completed') {
                    $finalStatus = 'completed';
                    break;
                }

                if ($fresh->status === 'failed') {
                    $finalStatus = 'failed';
                    break;
                }

                if ((microtime(true) - $startedAt) > $timeout) {
                    $finalStatus = 'timeout';
                    break;
                }

                $this->output->write('.');
            }

            $elapsed = (int) round(microtime(true) - $startedAt);

            switch ($finalStatus) {
                case 'completed':
                    $bias = $analysis->fresh()->bias_score;
                    $outlook = $analysis->fresh()->outlook;
                    $this->info(" ✓ done in {$elapsed}s (bias {$bias}/100, outlook {$outlook})");
                    $stats['completed']++;
                    break;

                case 'failed':
                    $err = $analysis->fresh()->error_message ?? 'unknown';
                    $this->warn(" ✗ failed in {$elapsed}s — {$err}");
                    $stats['failed']++;
                    break;

                case 'timeout':
                    $this->warn(" ⏱ timeout after {$elapsed}s — EA may be offline. Marking failed.");
                    $analysis->update([
                        'status' => 'failed',
                        'error_message' => "Weekly run timed out after {$timeout}s waiting for EA / OpenRouter",
                    ]);
                    $stats['timeout']++;
                    break;

                default:
                    $this->error(" ? analysis row disappeared");
                    $stats['failed']++;
            }
        }

        $totalElapsed = (int) round(microtime(true) - $overallStart);
        $this->newLine();
        $this->info(sprintf(
            "Done in %dm %ds — completed: %d, failed: %d, timeout: %d, skipped: %d",
            intdiv($totalElapsed, 60),
            $totalElapsed % 60,
            $stats['completed'],
            $stats['failed'],
            $stats['timeout'],
            $stats['skipped'],
        ));

        return $stats['failed'] + $stats['timeout'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
