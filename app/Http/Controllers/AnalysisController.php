<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeCurrencyJob;
use App\Models\ChartRequest;
use App\Models\CurrencyAnalysis;
use App\Models\ForexNews;
use App\Models\NewsFetchRequest;
use App\Models\OrderOpen;
use App\Models\OrderPending;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
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
                    'id', 'title', 'subject', 'category', 'currency', 'impact',
                    'forecast', 'previous', 'actual', 'event_at',
                    'source', 'mt5_event_id', 'external_id',
                    'source_url', 'unit', 'sector', 'frequency', 'event_type',
                    'measures', 'usual_effect', 'traders_care', 'notes',
                    // body_html is heavy — DON'T load in list; fetch on demand
                ])
                ->map(function ($n) {
                    return [
                        'id'          => $n->id,
                        'title'       => $n->subject ?: $n->title,
                        'category'    => $n->category,
                        'currency'    => $n->currency,
                        'impact'      => strtoupper($n->impact),
                        'forecast'    => $n->forecast,
                        'previous'    => $n->previous,
                        'actual'      => $n->actual,
                        'event_at'    => optional($n->event_at)->setTimezone('Asia/Singapore')->format('Y-m-d H:i'),
                        'event_at_iso'=> optional($n->event_at)->setTimezone('Asia/Singapore')->toIso8601String(),
                        'source'      => $n->source ?: 'forexfactory',
                        'mt5_event_id'=> $n->mt5_event_id,
                        'external_id' => $n->external_id,
                        // MT5 calendar metadata (v3.70+)
                        'source_url'  => $n->source_url,
                        'unit'        => $n->unit,
                        'sector'      => $n->sector,
                        'frequency'   => $n->frequency,
                        'event_type'  => $n->event_type,
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
        $now    = CarbonImmutable::now('Asia/Singapore');

        $analysis = CurrencyAnalysis::create([
            'symbol'     => $symbol,
            'week_start' => $now->startOfWeek()->toDateString(),
            'week_end'   => $now->endOfWeek()->toDateString(),
            'status'     => 'pending',
            'user_id'    => $request->user()?->id,
        ]);

        // Run analysis in the same PHP worker, after the HTTP response is sent
        // back to the browser. No queue worker required; the frontend polls
        // /analysis/{id}/status every 5s and reloads when it's done.
        Bus::dispatchAfterResponse(new AnalyzeCurrencyJob($analysis->id));

        return redirect()
            ->route('analysis.index', ['symbol' => $symbol])
            ->with('success', "Generating analysis for {$symbol}. Please wait...")
            ->with('analysis_id', $analysis->id);
    }

    public function show(int $id): JsonResponse
    {
        $analysis = CurrencyAnalysis::with([])->findOrFail($id);

        return response()->json($analysis);
    }

    /**
     * Queue an on-demand MT5 news fetch. EA picks it up within ~10s, pulls
     * MqlCalendarValue/Event/Country, pushes to /api/ea/news, then marks done.
     * The frontend polls /analysis/news-refresh-status to know when it's ready.
     */
    public function refreshMt5News(Request $request): JsonResponse
    {
        // Dedupe: if there's an in-flight request <2 min old, return it instead of creating a new one
        $existing = NewsFetchRequest::whereIn('status', ['pending', 'in_progress'])
            ->where('created_at', '>=', now()->subMinutes(2))
            ->latest('id')
            ->first();

        if ($existing) {
            return response()->json([
                'id'      => $existing->id,
                'status'  => $existing->status,
                'message' => 'A refresh is already in flight — waiting for the EA to pick it up.',
            ]);
        }

        $req = NewsFetchRequest::create([
            'status'       => 'pending',
            'requested_by' => $request->user()?->id,
        ]);

        return response()->json([
            'id'      => $req->id,
            'status'  => $req->status,
            'message' => 'Queued. The EA polls every ~10s and will fulfill this shortly.',
        ]);
    }

    /**
     * Fetch the full HTML body for one news item — only used when the trader
     * actually clicks a broker-news row. Keeps the list payload lean.
     */
    public function newsBody(int $id): JsonResponse
    {
        $news = ForexNews::select(['id','title','subject','category','source','event_at','currency','body_html','external_id'])
            ->findOrFail($id);

        return response()->json([
            'id'         => $news->id,
            'subject'    => $news->subject ?: $news->title,
            'category'   => $news->category,
            'source'     => $news->source,
            'currency'   => $news->currency,
            'event_at'   => optional($news->event_at)
                ->setTimezone('Asia/Singapore')->format('Y-m-d H:i'),
            'has_body'   => ! empty($news->body_html),
            'body_html'  => $news->body_html ?: null,
        ]);
    }

    public function refreshMt5NewsStatus(int $id): JsonResponse
    {
        $req = NewsFetchRequest::findOrFail($id);
        return response()->json([
            'id'              => $req->id,
            'status'          => $req->status,
            'events_imported' => $req->events_imported,
            'events_updated'  => $req->events_updated,
            'error_message'   => $req->error_message,
            'completed_at'    => $req->completed_at,
        ]);
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
