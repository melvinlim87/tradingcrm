<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Fetches OHLC (weekly / daily / 4h) from Yahoo Finance's public chart API.
 * No API key required.  Used as a fallback when the MT5 EA cannot deliver
 * chart screenshots — the AI analyzer turns this into a text-only prompt.
 *
 * Endpoint:
 *   https://query1.finance.yahoo.com/v8/finance/chart/EURUSD=X
 *     ?interval=1wk&range=2y
 *
 * Symbol mapping for our pairs:
 *   FX majors / crosses  -> "{SYMBOL}=X"   e.g. EURUSD=X
 *   XAUUSD              -> "GC=F" (Gold front-month future) is unreliable for "current price";
 *                          we instead use "XAUUSD=X" which Yahoo supports for spot gold.
 */
class MarketDataService
{
    private const BASE = 'https://query1.finance.yahoo.com/v8/finance/chart/';

    /**
     * Pull a structured snapshot for the symbol — weekly + daily bars + headline stats.
     *
     * Returned shape:
     * [
     *   'symbol'        => 'EURUSD',
     *   'yahoo_symbol'  => 'EURUSD=X',
     *   'current_price' => 1.08234,
     *   'as_of'         => '2026-05-26T12:00:00+00:00',
     *   'weekly'        => [ ['date'=>'2026-05-19','o'=>..,'h'=>..,'l'=>..,'c'=>..], ... 26 bars max ],
     *   'daily'         => [ ['date'=>'2026-05-25','o'=>..,'h'=>..,'l'=>..,'c'=>..], ... 60 bars max ],
     *   'stats'         => [
     *      'high_52w' => 1.1124, 'low_52w' => 1.0432,
     *      'pct_from_52w_high' => -2.5, 'pct_from_52w_low' => 3.6,
     *      'weekly_change_pct' => 0.4,  'monthly_change_pct' => -1.2,
     *      'ytd_change_pct' => 5.8,
     *      'recent_swing_high' => 1.0890, 'recent_swing_low' => 1.0760,
     *   ],
     * ]
     */
    public function snapshot(string $symbol): array
    {
        $yahooSymbol = $this->toYahooSymbol($symbol);

        $weekly = $this->fetchBars($yahooSymbol, '1wk', '2y');
        $daily  = $this->fetchBars($yahooSymbol, '1d',  '6mo');

        if (empty($weekly) && empty($daily)) {
            throw new RuntimeException("MarketData: no bars returned for {$yahooSymbol}");
        }

        // current_price = most-recent daily close (Yahoo updates this ~real-time during session)
        $currentPrice = end($daily)['c'] ?? end($weekly)['c'] ?? null;
        $asOf         = end($daily)['date'] ?? end($weekly)['date'] ?? null;

        $stats = $this->computeStats($weekly, $daily, $currentPrice);

        // Trim to a manageable size for the prompt (model gets weeks + last 60 days)
        $weekly = array_slice($weekly, -26);
        $daily  = array_slice($daily,  -60);

        return [
            'symbol'        => $symbol,
            'yahoo_symbol'  => $yahooSymbol,
            'current_price' => $currentPrice,
            'as_of'         => $asOf,
            'weekly'        => $weekly,
            'daily'         => $daily,
            'stats'         => $stats,
        ];
    }

    /**
     * Render the snapshot as a compact text block for inclusion in an LLM prompt.
     * Keeps the token count low while still giving the model enough numbers to
     * reason about structure, levels, and momentum.
     */
    public function renderForPrompt(array $snapshot): string
    {
        $digits = $this->guessDigits($snapshot['symbol']);
        $fmt = fn ($v) => $v === null ? '—' : number_format((float) $v, $digits, '.', '');
        $pct = fn ($v) => $v === null ? '—' : sprintf('%+.2f%%', (float) $v);

        $s = $snapshot['stats'];

        $lines = [];
        $lines[] = "Symbol: {$snapshot['symbol']}  (Yahoo: {$snapshot['yahoo_symbol']})";
        $lines[] = "As of:  {$snapshot['as_of']}";
        $lines[] = "Current price: {$fmt($snapshot['current_price'])}";
        $lines[] = '';
        $lines[] = '== Headline stats ==';
        $lines[] = "52-week high: {$fmt($s['high_52w'])}   (current is {$pct($s['pct_from_52w_high'])} from it)";
        $lines[] = "52-week low:  {$fmt($s['low_52w'])}    (current is {$pct($s['pct_from_52w_low'])} from it)";
        $lines[] = "Weekly change:  {$pct($s['weekly_change_pct'])}";
        $lines[] = "Monthly change: {$pct($s['monthly_change_pct'])}";
        $lines[] = "YTD change:     {$pct($s['ytd_change_pct'])}";
        $lines[] = "Recent swing high (last 8w): {$fmt($s['recent_swing_high'])}";
        $lines[] = "Recent swing low  (last 8w): {$fmt($s['recent_swing_low'])}";
        $lines[] = '';

        $lines[] = '== Weekly bars (last 26 weeks, O/H/L/C) ==';
        foreach ($snapshot['weekly'] as $bar) {
            $lines[] = sprintf(
                '%s  O:%s  H:%s  L:%s  C:%s',
                $bar['date'],
                $fmt($bar['o']), $fmt($bar['h']), $fmt($bar['l']), $fmt($bar['c']),
            );
        }
        $lines[] = '';

        $lines[] = '== Daily bars (last 60 days, O/H/L/C) ==';
        foreach ($snapshot['daily'] as $bar) {
            $lines[] = sprintf(
                '%s  O:%s  H:%s  L:%s  C:%s',
                $bar['date'],
                $fmt($bar['o']), $fmt($bar['h']), $fmt($bar['l']), $fmt($bar['c']),
            );
        }

        return implode("\n", $lines);
    }

    // ───────────────────────── internals ─────────────────────────

    /**
     * Map our broker-style symbol to Yahoo's notation.
     * Strip any broker suffix (.m, #, etc.) before mapping.
     */
    public function toYahooSymbol(string $symbol): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z]/i', '', $symbol));

        // Special cases first
        $special = [
            'XAUUSD' => 'XAUUSD=X',
            'XAGUSD' => 'XAGUSD=X',
        ];
        if (isset($special[$clean])) {
            return $special[$clean];
        }

        // Standard 6-char FX pair → append =X
        if (strlen($clean) === 6 && ctype_alpha($clean)) {
            return $clean . '=X';
        }

        // Fallback — let Yahoo decide
        return $clean;
    }

    /**
     * @return array<int, array{date:string,o:float,h:float,l:float,c:float}>
     */
    private function fetchBars(string $yahooSymbol, string $interval, string $range): array
    {
        $url = self::BASE . rawurlencode($yahooSymbol);

        $response = Http::timeout(20)
            ->withHeaders([
                // Yahoo will return 401 without a UA
                'User-Agent' => 'Mozilla/5.0 (QuantATM/1.0) AppleWebKit/537.36',
                'Accept'     => 'application/json',
            ])
            ->get($url, [
                'interval' => $interval,
                'range'    => $range,
            ]);

        if ($response->failed()) {
            Log::warning('MarketData: Yahoo request failed', [
                'symbol'   => $yahooSymbol,
                'interval' => $interval,
                'status'   => $response->status(),
                'body'     => mb_substr($response->body(), 0, 300),
            ]);
            return [];
        }

        $payload = $response->json();
        $result  = $payload['chart']['result'][0] ?? null;
        if (! $result) {
            return [];
        }

        $timestamps = $result['timestamp']                       ?? [];
        $quote      = $result['indicators']['quote'][0]          ?? [];
        $opens      = $quote['open']  ?? [];
        $highs      = $quote['high']  ?? [];
        $lows       = $quote['low']   ?? [];
        $closes     = $quote['close'] ?? [];

        $out = [];
        foreach ($timestamps as $i => $ts) {
            $o = $opens[$i]  ?? null;
            $h = $highs[$i]  ?? null;
            $l = $lows[$i]   ?? null;
            $c = $closes[$i] ?? null;
            if ($o === null || $h === null || $l === null || $c === null) {
                continue;
            }
            $out[] = [
                'date' => gmdate('Y-m-d', (int) $ts),
                'o'    => (float) $o,
                'h'    => (float) $h,
                'l'    => (float) $l,
                'c'    => (float) $c,
            ];
        }

        return $out;
    }

    private function computeStats(array $weekly, array $daily, ?float $currentPrice): array
    {
        $stats = [
            'high_52w'            => null,
            'low_52w'             => null,
            'pct_from_52w_high'   => null,
            'pct_from_52w_low'    => null,
            'weekly_change_pct'   => null,
            'monthly_change_pct'  => null,
            'ytd_change_pct'      => null,
            'recent_swing_high'   => null,
            'recent_swing_low'    => null,
        ];

        if ($currentPrice === null) {
            return $stats;
        }

        // 52-week range — last 52 weekly bars
        $last52 = array_slice($weekly, -52);
        if (! empty($last52)) {
            $stats['high_52w'] = max(array_column($last52, 'h'));
            $stats['low_52w']  = min(array_column($last52, 'l'));
            if ($stats['high_52w']) {
                $stats['pct_from_52w_high'] = (($currentPrice - $stats['high_52w']) / $stats['high_52w']) * 100;
            }
            if ($stats['low_52w']) {
                $stats['pct_from_52w_low']  = (($currentPrice - $stats['low_52w'])  / $stats['low_52w'])  * 100;
            }
        }

        // Weekly change: last weekly close vs prior weekly close
        if (count($weekly) >= 2) {
            $prev = $weekly[count($weekly) - 2]['c'];
            if ($prev) {
                $stats['weekly_change_pct'] = (($currentPrice - $prev) / $prev) * 100;
            }
        }

        // Monthly change: ~22 daily bars back
        if (count($daily) >= 23) {
            $monthAgo = $daily[count($daily) - 23]['c'];
            if ($monthAgo) {
                $stats['monthly_change_pct'] = (($currentPrice - $monthAgo) / $monthAgo) * 100;
            }
        }

        // YTD change: first daily bar of current year
        $year = (int) date('Y');
        foreach ($daily as $bar) {
            if (str_starts_with($bar['date'], (string) $year)) {
                if ($bar['c']) {
                    $stats['ytd_change_pct'] = (($currentPrice - $bar['c']) / $bar['c']) * 100;
                }
                break;
            }
        }

        // Recent swing levels — last 8 weekly bars
        $last8 = array_slice($weekly, -8);
        if (! empty($last8)) {
            $stats['recent_swing_high'] = max(array_column($last8, 'h'));
            $stats['recent_swing_low']  = min(array_column($last8, 'l'));
        }

        // Round to sensible precision
        foreach (['high_52w', 'low_52w', 'recent_swing_high', 'recent_swing_low'] as $k) {
            if ($stats[$k] !== null) {
                $stats[$k] = round($stats[$k], 5);
            }
        }
        foreach (['pct_from_52w_high', 'pct_from_52w_low', 'weekly_change_pct', 'monthly_change_pct', 'ytd_change_pct'] as $k) {
            if ($stats[$k] !== null) {
                $stats[$k] = round($stats[$k], 2);
            }
        }

        return $stats;
    }

    private function guessDigits(string $symbol): int
    {
        $u = strtoupper($symbol);
        if (str_contains($u, 'JPY')) return 3;
        if (str_starts_with($u, 'XAU')) return 2;
        if (str_starts_with($u, 'XAG')) return 3;
        return 5;
    }
}
