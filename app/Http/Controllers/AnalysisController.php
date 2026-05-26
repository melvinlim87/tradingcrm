<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeCurrencyJob;
use App\Models\ChartRequest;
use App\Models\CurrencyAnalysis;
use App\Models\ForexNews;
use App\Models\OrderOpen;
use App\Models\OrderPending;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AnalysisController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $symbol = strtoupper($request->query('symbol', 'AUDUSD'));
        // Explicit currency override (so SGD doesn't get read as "USD"
        // from "USDSGD". The Vue selectCurrency() always sends this.)
        $currency = strtoupper($request->query('currency', ''));
        if ($currency === '' || strlen($currency) !== 3) {
            // Fallback heuristic: pick a 3-char chunk that is NOT USD
            // (so USDSGD → SGD, USDCAD → CAD, EURUSD → EUR, AUDUSD → AUD)
            $left = substr($symbol, 0, 3);
            $right = substr($symbol, 3, 3);
            $currency = ($left !== 'USD' && strlen($left) === 3) ? $left
                : (($right !== '' && strlen($right) === 3) ? $right : $left);
        }

        $latest = CurrencyAnalysis::where('symbol', $symbol)
            ->latest('created_at')
            ->first();

        $history = CurrencyAnalysis::where('symbol', $symbol)
            ->where('status', 'completed')
            ->latest('created_at')
            ->limit(10)
            ->get(['id', 'symbol', 'week_start', 'week_end', 'outlook', 'created_at']);

        $activeChartRequest = $latest
            ? ChartRequest::where('currency_analysis_id', $latest->id)
                ->latest('id')
                ->first(['id', 'status', 'symbol', 'created_at'])
            : null;

        $news = $this->fetchNewsForPair($symbol);

        // Distinct symbols currently being traded (open positions + pending orders),
        // normalised (strip broker suffix like # / .m / .raw)
        $tradedSymbols = $this->tradedSymbols();

        return Inertia::render('Analysis/Index', [
            'symbol' => $symbol,
            'currency' => $currency,
            'analysis' => $latest,
            'history' => $history,
            'chart_request' => $activeChartRequest,
            'news' => $news,
            'traded_symbols' => $tradedSymbols,
        ]);
    }

    private function tradedSymbols(): array
    {
        $open    = OrderOpen::query()->distinct()->pluck('symbol');
        $pending = OrderPending::query()->distinct()->pluck('symbol');

        return $open->merge($pending)
            ->map(fn ($s) => preg_replace('/[^A-Z]/', '', strtoupper((string) $s)))
            ->filter(fn ($s) => strlen($s) >= 6)   // need at least a pair
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Build past-week / this-week / next-week news lists for the pair.
     */
    private function fetchNewsForPair(string $symbol): array
    {
        $clean = preg_replace('/[^A-Z]/', '', strtoupper($symbol));
        $currencies = [substr($clean, 0, 3), substr($clean, 3, 3)];
        $currencies = array_values(array_filter($currencies, fn ($c) => strlen($c) === 3));

        $now = CarbonImmutable::now('Asia/Singapore');

        $window = fn (CarbonImmutable $anchor) =>
            ForexNews::forCurrencies($currencies)
                ->between($anchor->startOfWeek()->utc(), $anchor->endOfWeek()->utc())
                ->orderBy('event_at')
                ->get([
                    'id', 'title', 'currency', 'impact',
                    'forecast', 'previous', 'actual', 'event_at',
                    'source', 'mt5_event_id',
                    'measures', 'usual_effect', 'traders_care', 'notes',
                ])
                ->map(function ($n) {
                    return [
                        'id'          => $n->id,
                        'title'       => $n->title,
                        'currency'    => $n->currency,
                        'impact'      => strtoupper($n->impact),
                        'forecast'    => $n->forecast,
                        'previous'    => $n->previous,
                        'actual'      => $n->actual,
                        'event_at'    => optional($n->event_at)->setTimezone('Asia/Singapore')->format('Y-m-d H:i'),
                        'event_at_iso'=> optional($n->event_at)->setTimezone('Asia/Singapore')->toIso8601String(),
                        'source'      => $n->source ?: 'forexfactory',
                        'mt5_event_id'=> $n->mt5_event_id,
                        // MT5 popup details (only populated for some events)
                        'measures'    => $n->measures,
                        'usual_effect'=> $n->usual_effect,
                        'traders_care'=> $n->traders_care,
                        'notes'       => $n->notes,
                    ];
                });

        return [
            'past'     => $window($now->subWeek()),
            'this'     => $window($now),
            'upcoming' => $window($now->addWeek()),
            'currencies' => $currencies,
        ];
    }

    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'symbol' => 'required|string|max:16|regex:/^[A-Za-z]{6,8}$/',
        ]);

        $symbol = strtoupper($data['symbol']);
        $now = CarbonImmutable::now('Asia/Singapore');

        $analysis = CurrencyAnalysis::create([
            'symbol' => $symbol,
            'week_start' => $now->startOfWeek()->toDateString(),
            'week_end' => $now->endOfWeek()->toDateString(),
            'status' => 'pending',
            'user_id' => $request->user()?->id,
        ]);

        ChartRequest::create([
            'currency_analysis_id' => $analysis->id,
            'symbol' => $symbol,
            'status' => 'pending',
        ]);

        return redirect()
            ->route('analysis.index', ['symbol' => $symbol])
            ->with('success', "Analysis queued for {$symbol}. ")
            ->with('analysis_id', $analysis->id);
    }

    public function show(int $id): JsonResponse
    {
        $analysis = CurrencyAnalysis::with([])->findOrFail($id);

        return response()->json($analysis);
    }

    public function status(int $id): JsonResponse
    {
        $analysis = CurrencyAnalysis::findOrFail($id);

        $chartRequest = ChartRequest::where('currency_analysis_id', $id)
            ->latest('id')
            ->first();

        return response()->json([
            'analysis_id' => $analysis->id,
            'status' => $analysis->status,
            'error_message' => $analysis->error_message,
            'chart_request' => $chartRequest ? [
                'status' => $chartRequest->status,
                'received_timeframes' => $chartRequest->exports()->pluck('timeframe'),
                'required_timeframes' => ChartRequest::REQUIRED_TIMEFRAMES,
            ] : null,
            'updated_at' => $analysis->updated_at,
        ]);
    }
}
