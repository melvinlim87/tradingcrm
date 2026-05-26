<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ForexNews;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class EaNewsController extends Controller
{
    /**
     * Latest news around "now" for the EA dashboard panel.
     * Returns up to 10 entries within (now - 12h, now + 72h), oldest first
     * so the panel naturally renders "what's about to happen" at the bottom.
     */
    public function latest(Request $request): JsonResponse
    {
        $limit = (int) min(20, max(5, (int) $request->query('limit', 10)));
        $now = CarbonImmutable::now();
        $from = $now->subHours(12)->utc();
        $to   = $now->addHours(72)->utc();

        $rows = ForexNews::query()
            ->whereBetween('event_at', [$from, $to])
            ->orderBy('event_at')
            ->limit($limit)
            ->get(['id', 'title', 'currency', 'impact', 'forecast', 'previous', 'actual', 'event_at']);

        return response()->json([
            'data' => $rows->map(function ($n) {
                return [
                    'id' => $n->id,
                    'title' => $n->title,
                    'currency' => $n->currency,
                    'impact' => strtoupper((string) $n->impact),
                    'forecast' => $n->forecast,
                    'previous' => $n->previous,
                    'actual' => $n->actual,
                    'event_at' => optional($n->event_at)->setTimezone('Asia/Singapore')->format('m-d H:i'),
                ];
            }),
            'as_of' => $now->setTimezone('Asia/Singapore')->format('Y-m-d H:i'),
        ]);
    }

    /**
     * Receive a batch of MT5-native calendar events from the EA and upsert into forex_news.
     * MetaQuotes does NOT expose a public REST API for the MT5 calendar, so the EA acts as
     * the only conduit: it polls MqlCalendarValue/Event/Country via MQL5 and POSTs to us.
     *
     * Expected payload:
     *  {
     *    "events": [
     *      { "event_id": 12345, "title": "...", "currency": "USD", "impact": "HIGH|MEDIUM|LOW",
     *        "forecast": "...", "previous": "...", "actual": "...",
     *        "event_at": "2026-05-22T14:30:00Z" },
     *      ...
     *    ]
     *  }
     */
    public function push(Request $request): JsonResponse
    {
        $events = (array) $request->input('events', []);
        if (empty($events)) {
            return response()->json(['error' => 'No events provided'], 422);
        }

        $imported = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($events as $e) {
            try {
                $eventAt = $this->parseDate($e['event_at'] ?? null);
                $title = trim((string) ($e['title'] ?? ''));
                if (! $eventAt || $title === '') {
                    $skipped++;
                    continue;
                }

                $record = ForexNews::updateOrCreate(
                    [
                        'title' => $title,
                        'event_at' => $eventAt,
                    ],
                    [
                        'currency' => strtoupper(trim((string) ($e['currency'] ?? ''))),
                        'impact' => $this->normalizeImpact($e['impact'] ?? 'LOW'),
                        'forecast' => $this->nullIfEmpty($e['forecast'] ?? null),
                        'previous' => $this->nullIfEmpty($e['previous'] ?? null),
                        'actual' => $this->nullIfEmpty($e['actual'] ?? null),
                        'raw_date' => $e['event_at'] ?? null,
                        'source' => 'mt5',
                        'mt5_event_id' => isset($e['event_id']) ? (int) $e['event_id'] : null,
                    ],
                );

                $record->wasRecentlyCreated ? $imported++ : $updated++;
            } catch (Throwable $ex) {
                Log::warning('EA MT5 news push: bad item', [
                    'error' => $ex->getMessage(),
                    'item'  => $e,
                ]);
                $skipped++;
            }
        }

        return response()->json([
            'imported' => $imported,
            'updated'  => $updated,
            'skipped'  => $skipped,
        ]);
    }

    private function parseDate(?string $raw): ?Carbon
    {
        if (! $raw) return null;
        try { return Carbon::parse($raw)->utc(); } catch (Throwable) { return null; }
    }

    private function normalizeImpact(?string $value): string
    {
        $value = strtoupper(trim((string) $value));
        return match ($value) {
            'HIGH'           => 'HIGH',
            'MED', 'MEDIUM'  => 'MEDIUM',
            'HOLIDAY'        => 'HOLIDAY',
            default          => 'LOW',
        };
    }

    private function nullIfEmpty(?string $v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
}
