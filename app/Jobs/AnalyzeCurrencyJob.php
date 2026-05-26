<?php

namespace App\Jobs;

use App\Models\ChartRequest;
use App\Models\CurrencyAnalysis;
use App\Models\ForexNews;
use App\Services\AisitaChartService;
use App\Services\MarketDataService;
use App\Services\OpenRouterService;
use App\Services\PromptRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeCurrencyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $analysisId)
    {
    }

    public function handle(
        OpenRouterService $openRouter,
        PromptRenderer $renderer,
        MarketDataService $marketData,
        AisitaChartService $aisitaCharts,
    ): void {
        $analysis = CurrencyAnalysis::findOrFail($this->analysisId);

        // Existing chart request (EA-driven path, if any)
        $chartRequest = ChartRequest::where('currency_analysis_id', $analysis->id)
            ->latest('id')
            ->first();

        $analysis->update(['status' => 'processing', 'error_message' => null]);

        try {
            $currencies = $this->extractCurrencies($analysis->symbol);
            $now        = CarbonImmutable::now('Asia/Singapore');

            // PAST: last 1 month of HIGH-impact events
            $newsLast = $this->fetchNewsBetween(
                $currencies,
                $now->subMonth(),
                $now->subDay()->endOfDay(),
            );
            // THIS WEEK
            $newsThis = $this->fetchNewsForWeek($currencies, $now);
            // NEXT WEEK
            $newsNext = $this->fetchNewsForWeek($currencies, $now->addWeek());

            // ── Pick chart source: Aisita (preferred) → EA → text-only ──
            $mode          = 'text_only';
            $chartUrls     = [];
            $promptImages  = [];
            $marketBlock   = '';
            $priceSnapshot = ['bid' => null, 'ask' => null, 'digits' => null, 'captured_at' => null, 'display' => 'unavailable'];

            if ($aisitaCharts->isConfigured()) {
                try {
                    $fetched = $aisitaCharts->fetch($analysis->symbol);
                    $chartUrls    = $aisitaCharts->persistForAnalysis($analysis->id, $fetched['timeframes']);
                    $promptImages = $fetched['data_uris'];
                    $marketBlock  = "(Chart screenshots are attached below — M15 / H1 / H4 from Aisita. Use them as the primary source for structure / S&R / momentum. Recall that this is a SHORT-term view; the bias should reflect the next 1-5 trading days.)";
                    $mode         = 'aisita_vision';

                    // Stash a basic price snapshot from MarketData so the prompt
                    // header still shows a current price reference.
                    try {
                        $md = $marketData->snapshot($analysis->symbol);
                        $priceSnapshot = $this->priceSnapshotFromMarketData($md);
                    } catch (Throwable $e) {
                        Log::info('AnalyzeCurrencyJob: market-data snapshot failed (non-fatal)', [
                            'symbol' => $analysis->symbol,
                            'error'  => $e->getMessage(),
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('AnalyzeCurrencyJob: Aisita fetch failed, falling back', [
                        'symbol' => $analysis->symbol,
                        'error'  => $e->getMessage(),
                    ]);
                }
            }

            if ($mode === 'text_only' && $chartRequest && $chartRequest->hasAllTimeframes()) {
                $chartUrls     = $chartRequest->imageUrlMap();
                $promptImages  = $this->encodeChartsAsDataUris($chartRequest);
                $priceSnapshot = $this->priceSnapshot($chartRequest);
                $marketBlock   = "(Chart screenshots are attached below — H4 / D1 / W1 from the MT5 EA. Use them as the primary source for structure / S&R / momentum.)";
                $mode          = 'ea_vision';
            }

            if ($mode === 'text_only') {
                $snapshot      = $marketData->snapshot($analysis->symbol);
                $marketBlock   = $marketData->renderForPrompt($snapshot);
                $priceSnapshot = $this->priceSnapshotFromMarketData($snapshot);
            }

            $prompt = $renderer->render([
                'symbol'           => $analysis->symbol,
                'current_date'     => $now->toDateString(),
                'current_price'    => $priceSnapshot['display'],
                'market_data'      => $marketBlock,
                'news_last_week'   => $this->formatNews($newsLast),
                'news_this_week'   => $this->formatNews($newsThis),
                'news_next_week'   => $this->formatNews($newsNext),
                'response_schema'  => $this->responseSchema(),
            ]);

            $result = $openRouter->analyze($prompt, $promptImages);
            $parsed = $result['parsed'];

            $analysis->update([
                'charts' => $chartUrls,
                'news_snapshot' => [
                    'last_week' => $newsLast->pluck('id')->all(),
                    'this_week' => $newsThis->pluck('id')->all(),
                    'next_week' => $newsNext->pluck('id')->all(),
                    'price'     => $priceSnapshot,
                    'mode'      => $mode,
                ],
                'outlook' => $this->normalizeOutlook($parsed['outlook'] ?? null),
                'bias_score' => $this->clampBiasScore($parsed['bias_score'] ?? null),
                'confidence' => $this->clampConfidence($parsed['confidence'] ?? null),
                'summary' => $parsed['summary'] ?? null,
                'market_structure' => $parsed['market_structure'] ?? null,
                'support_resistance' => $parsed['support_resistance'] ?? null,
                'news_impact' => $parsed['news_impact'] ?? null,
                'trade_ideas' => $parsed['trade_ideas'] ?? null,
                'prompt_used' => $prompt,
                'raw_response' => $result['raw'],
                'openrouter_model' => $result['model'],
                'tokens_used' => $result['tokens'],
                'status' => 'completed',
            ]);
        } catch (Throwable $e) {
            $analysis->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('AnalyzeCurrencyJob failed', [
                'analysis_id' => $this->analysisId,
                'symbol' => $analysis->symbol,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /** Display-friendly price block when there's no EA bid/ask snapshot. */
    private function priceSnapshotFromMarketData(array $snapshot): array
    {
        $price = $snapshot['current_price'] ?? null;
        if ($price === null) {
            return ['bid' => null, 'ask' => null, 'digits' => null, 'captured_at' => null, 'display' => 'unavailable'];
        }
        $digits = str_contains(strtoupper($snapshot['symbol']), 'JPY') ? 3
                  : (str_starts_with(strtoupper($snapshot['symbol']), 'XAU') ? 2 : 5);
        return [
            'bid'         => $price,
            'ask'         => $price,
            'mid'         => $price,
            'digits'      => $digits,
            'captured_at' => $snapshot['as_of'] ?? null,
            'display'     => sprintf(
                'Spot %s  (source: Yahoo Finance, as of %s)',
                number_format($price, $digits, '.', ''),
                $snapshot['as_of'] ?? '?',
            ),
        ];
    }


    public function failed(Throwable $e): void
    {
        CurrencyAnalysis::where('id', $this->analysisId)->update([
            'status' => 'failed',
            'error_message' => $e->getMessage(),
        ]);
    }

    private function extractCurrencies(string $symbol): array
    {
        $clean = preg_replace('/[^A-Z]/', '', strtoupper($symbol));
        $pair = [substr($clean, 0, 3), substr($clean, 3, 3)];

        return array_values(array_filter($pair, fn ($c) => strlen($c) === 3));
    }

    private function fetchNewsForWeek(array $currencies, CarbonImmutable $anchor)
    {
        return $this->fetchNewsBetween(
            $currencies,
            $anchor->startOfWeek(),
            $anchor->endOfWeek(),
        );
    }

    private function fetchNewsBetween(array $currencies, CarbonImmutable $from, CarbonImmutable $to)
    {
        // Only HIGH impact events are fed to the AI — keeps the prompt focused
        // on news that actually moves price, and cuts OpenRouter token cost.
        return ForexNews::forCurrencies($currencies)
            ->between($from->utc(), $to->utc())
            ->where('impact', 'HIGH')
            ->orderBy('event_at')
            ->get();
    }

    private function formatNews($news): string
    {
        if ($news->isEmpty()) {
            return 'None.';
        }

        return $news->map(function ($n) {
            return sprintf(
                '- [%s] %s | %s @ %s GMT+8 | F:%s P:%s A:%s',
                $n->impact,
                $n->title,
                $n->currency,
                $n->event_at->setTimezone('Asia/Singapore')->format('Y-m-d H:i'),
                $n->forecast ?: '-',
                $n->previous ?: '-',
                $n->actual ?: '-',
            );
        })->implode("\n");
    }

    private function normalizeOutlook(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['bullish', 'bearish', 'neutral'], true) ? $value : null;
    }

    private function clampConfidence($value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    private function clampBiasScore($value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) max(0, min(100, (int) round((float) $value)));
    }

    /**
     * Get the most recent bid/ask the EA sent with the chart uploads.
     * Used both for the prompt (so the model knows current price) and
     * for displaying on the analysis page (so the user can verify levels).
     *
     * @return array{bid: ?float, ask: ?float, digits: ?int, captured_at: ?string, display: string}
     */
    private function priceSnapshot(\App\Models\ChartRequest $chartRequest): array
    {
        $latest = $chartRequest->exports()
            ->whereNotNull('bid')
            ->orderByDesc('captured_at')
            ->first();

        if (! $latest) {
            return [
                'bid' => null, 'ask' => null, 'digits' => null,
                'captured_at' => null,
                'display' => 'unavailable',
            ];
        }

        $digits = $latest->digits ?: 5;
        $bid = (float) $latest->bid;
        $ask = $latest->ask ? (float) $latest->ask : null;
        $mid = $ask ? round(($bid + $ask) / 2, $digits) : $bid;

        return [
            'bid' => $bid,
            'ask' => $ask,
            'mid' => $mid,
            'digits' => $digits,
            'captured_at' => optional($latest->captured_at)->toIso8601String(),
            'display' => sprintf(
                'Bid %s / Ask %s (captured %s GMT+8)',
                number_format($bid, $digits, '.', ''),
                $ask !== null ? number_format($ask, $digits, '.', '') : '—',
                optional($latest->captured_at)->setTimezone('Asia/Singapore')->format('Y-m-d H:i') ?: '?',
            ),
        ];
    }

    /**
     * Encode each chart PNG as a base64 data: URI so OpenRouter can analyze them
     * without trying to fetch a localhost URL it can't reach.
     *
     * @return array<string,string>  e.g. ["H4" => "data:image/png;base64,...", ...]
     */
    private function encodeChartsAsDataUris(\App\Models\ChartRequest $chartRequest): array
    {
        $exports = $chartRequest->exports()
            ->orderByRaw("FIELD(timeframe, 'H4','D1','W1')")
            ->get();

        $out = [];
        foreach ($exports as $exp) {
            $absolutePath = storage_path('app/public/' . $exp->storage_path);
            if (! is_file($absolutePath)) {
                Log::warning('Chart file missing on disk', ['path' => $absolutePath]);
                continue;
            }
            $binary = @file_get_contents($absolutePath);
            if ($binary === false) {
                continue;
            }
            $mime = $exp->mime ?: 'image/png';
            $out[$exp->timeframe] = 'data:' . $mime . ';base64,' . base64_encode($binary);
        }

        return $out;
    }

    private function responseSchema(): string
    {
        return json_encode([
            'outlook' => 'bullish | bearish | neutral',
            'bias_score' => 'integer 0-100  (0=strong bearish, 50=neutral, 100=strong bullish) — REQUIRED, drives the on-screen meter gauge',
            'confidence' => 'number 0.0–1.0',
            'summary' => 'one paragraph weekly outlook',
            'market_structure' => [
                'trend' => 'uptrend | downtrend | sideways',
                'phase' => 'accumulation | markup | distribution | markdown',
                'momentum' => 'strong | moderate | weak',
                'key_observations' => ['array of short strings'],
            ],
            'support_resistance' => [
                'supports' => [[
                    'price' => 0.0,
                    'strength' => 'strong | medium | weak',
                    'note' => 'string',
                ]],
                'resistances' => [[
                    'price' => 0.0,
                    'strength' => 'strong | medium | weak',
                    'note' => 'string',
                ]],
            ],
            'news_impact' => [
                'past_events' => ['array of short strings — events that already happened this/last week and moved price'],
                'upcoming_events' => ['array of short strings — events still to come this/next week to watch'],
            ],
            'trade_ideas' => [[
                'direction' => 'long | short',
                'entry' => 0.0,
                'stop_loss' => 0.0,
                'take_profit' => 0.0,
                'rationale' => 'string',
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
