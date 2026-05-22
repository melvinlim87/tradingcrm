<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ForexNews;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
