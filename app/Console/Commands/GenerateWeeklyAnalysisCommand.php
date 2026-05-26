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
                            {--user= : User ID to attribute the analyses to}';

    protected $description = 'Queue a fresh AI analysis for each major FX pair so users see results on entry';

    /**
     * Default set of pairs (one per base currency from the analysis page).
     * Override via --symbols=EURUSD,GBPUSD,...
     */
    private const DEFAULT_SYMBOLS = [
        'AUDUSD', 'USDCAD', 'EURUSD', 'GBPUSD',
        'USDCHF', 'NZDUSD', 'USDSGD', 'USDJPY',
    ];

    public function handle(): int
    {
        $symbols = $this->option('symbols')
            ? array_map('trim', explode(',', $this->option('symbols')))
            : self::DEFAULT_SYMBOLS;

        $userId = $this->option('user') ? (int) $this->option('user') : null;
        $now = CarbonImmutable::now('Asia/Singapore');
        $weekStart = $now->startOfWeek()->toDateString();
        $weekEnd = $now->endOfWeek()->toDateString();

        $queued = 0;
        $skipped = 0;

        foreach ($symbols as $symbol) {
            $symbol = strtoupper(trim($symbol));
            if ($symbol === '') {
                continue;
            }

            // Skip if a completed analysis already exists for this week's start
            $existing = CurrencyAnalysis::where('symbol', $symbol)
                ->where('week_start', $weekStart)
                ->where('status', 'completed')
                ->exists();
            if ($existing) {
                $this->line("  • {$symbol}  ↩ already completed this week");
                $skipped++;
                continue;
            }

            $analysis = CurrencyAnalysis::create([
                'symbol' => $symbol,
                'week_start' => $weekStart,
                'week_end' => $weekEnd,
                'status' => 'pending',
                'user_id' => $userId,
            ]);

            ChartRequest::create([
                'currency_analysis_id' => $analysis->id,
                'symbol' => $symbol,
                'status' => 'pending',
            ]);

            $this->info("  ✓ {$symbol}  queued (analysis #{$analysis->id})");
            $queued++;
        }

        $this->newLine();
        $this->info("Done. Queued: {$queued}, Skipped: {$skipped}");
        $this->line('  → The ChartExporter EA will pick these up within 10s each, capture H4/D1/W1 + upload, then the analysis runs sync.');

        return Command::SUCCESS;
    }
}
