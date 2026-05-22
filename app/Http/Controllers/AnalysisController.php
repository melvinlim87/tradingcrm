<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeCurrencyJob;
use App\Models\ChartRequest;
use App\Models\CurrencyAnalysis;
use App\Models\ForexNews;
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

        return Inertia::render('Analysis/Index', [
            'symbol' => $symbol,
            'analysis' => $latest,
            'history' => $history,
            'chart_request' => $activeChartRequest,
            'news' => $news,
        ]);
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
                ])
                ->map(function ($n) {
                    return [
                        'id' => $n->id,
                        'title' => $n->title,
                        'currency' => $n->currency,
                        'impact' => strtoupper($n->impact),
                        'forecast' => $n->forecast,
                        'previous' => $n->previous,
                        'actual' => $n->actual,
                        'event_at' => optional($n->event_at)->setTimezone('Asia/Singapore')->format('Y-m-d H:i'),
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
            ->with('success', "Analysis queued for {$symbol}. The ChartExporter EA will capture H4/D1/W1 charts within ~10s, then OpenRouter will run the analysis.")
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
