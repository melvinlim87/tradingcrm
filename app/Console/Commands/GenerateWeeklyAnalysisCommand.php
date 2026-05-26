<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeCurrencyJob;
use App\Models\CurrencyAnalysis;
use App\Models\OrderOpen;
use App\Models\OrderPending;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class GenerateWeeklyAnalysisCommand extends Command
{
    protected $signature = 'analysis:generate-weekly
                            {--symbols= : Comma-separated list of symbols (defaults to the built-in 15-pair set)}
                            {--user= : User ID to attribute the analyses to}
                            {--include-traded : Also include every symbol currently in open positions / pending orders}
                            {--traded-only : Only run for currently-traded symbols (overrides --symbols and the default set)}
                            {--force : Re-generate even if a completed analysis exists for this week}';

    protected $description = 'Run weekly AI analysis for each configured symbol — fetches charts + news and stores the result. Runs synchronously from the CLI; no queue worker needed.';

    /**
     * Default set of pairs covered by the weekly auto-analysis.
     */
    private const DEFAULT_SYMBOLS = [
        // USD majors
        'AUDUSD', 'USDCAD', 'EURUSD', 'GBPUSD',
        'USDCHF', 'NZDUSD', 'USDSGD', 'USDJPY',
        // Cross pairs + commodity
        'AUDCAD', 'EURGBP', 'XAUUSD',
        'EURCHF', 'CADCHF', 'NZDCHF', 'EURNZD',
    ];

    public function handle(): int
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $tradedOnly    = (bool) $this->option('traded-only');
        $includeTraded = (bool) $this->option('include-traded');

        if ($tradedOnly) {
            $symbols = $this->tradedSymbols();
            if (empty($symbols)) {
                $this->warn('No symbols are currently being traded (no open positions / pending orders). Nothing to do.');
                return Command::SUCCESS;
            }
        } else {
            $symbols = $this->option('symbols')
                ? array_filter(array_map('trim', explode(',', $this->option('symbols'))))
                : self::DEFAULT_SYMBOLS;

            if ($includeTraded) {
                $symbols = array_values(array_unique(array_merge(
                    array_map('strtoupper', $symbols),
                    $this->tradedSymbols(),
                )));
            }
        }

        $userId = $this->option('user') ? (int) $this->option('user') : null;
        $force  = (bool) $this->option('force');

        $now       = CarbonImmutable::now('Asia/Singapore');
        $weekStart = $now->startOfWeek()->toDateString();
        $weekEnd   = $now->endOfWeek()->toDateString();

        $this->info("══ Weekly analysis ══");
        $this->line('  Symbols: ' . count($symbols) . ' (' . implode(', ', $symbols) . ')');
        $this->line("  Week:    {$weekStart} → {$weekEnd}");
        $this->newLine();

        $completed = 0;
        $failed    = 0;
        $skipped   = 0;
        $startedAt = microtime(true);

        foreach ($symbols as $symbol) {
            $symbol = strtoupper($symbol);

            // Skip if already completed this week (unless --force)
            if (! $force) {
                $existing = CurrencyAnalysis::where('symbol', $symbol)
                    ->where('week_start', $weekStart)
                    ->where('status', 'completed')
                    ->first();
                if ($existing) {
                    $this->line("  ↩ {$symbol}  already completed this week (#{$existing->id})");
                    $skipped++;
                    continue;
                }
            }

            // Create the analysis row (pending) — the job will flip it to completed/failed
            $analysis = CurrencyAnalysis::create([
                'symbol'     => $symbol,
                'week_start' => $weekStart,
                'week_end'   => $weekEnd,
                'status'     => 'pending',
                'user_id'    => $userId,
            ]);

            $tStart = microtime(true);
            $this->line("  → {$symbol}  analysis #{$analysis->id} running...");

            try {
                AnalyzeCurrencyJob::dispatchSync($analysis->id);

                $analysis->refresh();
                $elapsed = round(microtime(true) - $tStart, 1);

                if ($analysis->status === 'completed') {
                    $this->info(sprintf(
                        '    ✓ %s  outlook=%s  bias=%s  (%ss)',
                        $symbol,
                        $analysis->outlook ?? '—',
                        $analysis->bias_score ?? '—',
                        $elapsed,
                    ));
                    $completed++;
                } else {
                    $this->error(sprintf(
                        '    ✗ %s  status=%s  err=%s',
                        $symbol,
                        $analysis->status,
                        mb_strimwidth($analysis->error_message ?? '', 0, 100, '…'),
                    ));
                    $failed++;
                }
            } catch (Throwable $e) {
                $analysis->update([
                    'status'        => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
                $this->error("    ✗ {$symbol}  threw: " . mb_strimwidth($e->getMessage(), 0, 120, '…'));
                $failed++;
            }
        }

        $totalElapsed = (int) round(microtime(true) - $startedAt);

        $this->newLine();
        $this->info(sprintf(
            'Done in %dm %02ds — completed: %d, failed: %d, skipped: %d',
            intdiv($totalElapsed, 60),
            $totalElapsed % 60,
            $completed,
            $failed,
            $skipped,
        ));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Distinct symbols currently being traded (open positions + pending orders),
     * normalised to canonical form (broker suffixes like .m / # / .raw stripped).
     *
     * @return array<int,string>
     */
    private function tradedSymbols(): array
    {
        $open    = OrderOpen::query()->distinct()->pluck('symbol');
        $pending = OrderPending::query()->distinct()->pluck('symbol');

        return $open->merge($pending)
            ->map(fn ($s) => preg_replace('/[^A-Z]/', '', strtoupper((string) $s)))
            ->filter(fn ($s) => strlen($s) >= 6 && strlen($s) <= 8)
            ->unique()
            ->values()
            ->all();
    }
}
