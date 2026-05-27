<?php

namespace App\Http\Controllers;

use App\Models\Mt5Account;
use App\Models\OrderHistory;
use App\Models\OrderOpen;
use App\Models\OrderPending;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DashboardController extends Controller
{
    /** How many days of closed trades to surface on the dashboard. */
    private const HISTORY_WINDOW_DAYS = 90;

    /** Max rows shown per tab (per account). */
    private const ORDERS_PER_ACCOUNT_LIMIT = 50;

    public function index(\Illuminate\Http\Request $request): InertiaResponse
    {
        $user = $request->user();

        $accounts = $user->visibleAccountsQuery()
            ->with('creator:id,name,email,role')
            ->orderBy('account_number')
            ->get();

        $accountIds = $accounts->pluck('id')->all();
        $historyCutoff = CarbonImmutable::now()->subDays(self::HISTORY_WINDOW_DAYS)->utc();

        // ── BATCH-LOAD orders (1 SQL per type instead of N) ────────────────
        $openByAcc    = $this->loadOrdersBatched(OrderOpen::class,    $accountIds, 'opened_at');
        $pendingByAcc = $this->loadOrdersBatched(OrderPending::class, $accountIds, 'created_ea_at');
        $historyByAcc = $this->loadOrdersBatched(OrderHistory::class, $accountIds, 'closed_at', $historyCutoff);

        // ── BATCH-AGGREGATE profit series for all accounts in one SQL ─────
        $profitSeriesByAcc = $this->aggregateProfitSeriesByAccount($accountIds, $historyCutoff);

        // ── BATCH-AGGREGATE all-time closed profit per account (one SQL) ──
        // Used as the ROI numerator (closed_profit / capital_base).
        // No date cutoff: ROI is a lifetime metric.
        $closedProfitByAcc = OrderHistory::whereIn('mt5_account_id', $accountIds)
            ->selectRaw('mt5_account_id, SUM(pnl) AS total_pnl')
            ->groupBy('mt5_account_id')
            ->pluck('total_pnl', 'mt5_account_id')
            ->map(fn ($v) => (float) $v)
            ->all();

        $accounts = $accounts->map(function (Mt5Account $acc) use ($openByAcc, $pendingByAcc, $historyByAcc, $profitSeriesByAcc, $closedProfitByAcc) {
            $acc->setRelation('openOrders',     $openByAcc[$acc->id]    ?? collect());
            $acc->setRelation('pendingOrders',  $pendingByAcc[$acc->id] ?? collect());
            $acc->setRelation('historyOrders',  $historyByAcc[$acc->id] ?? collect());
            $acc->setAttribute('profit_series', $profitSeriesByAcc[$acc->id] ?? []);
            // Inject closed_profit_total so Mt5Account::roi_pct accessor
            // doesn't need to re-query OrderHistory per account.
            $acc->setAttribute('closed_profit_total', $closedProfitByAcc[$acc->id] ?? 0.0);
            return $acc;
        });

        // ── Aggregates ────────────────────────────────────────────────────
        $totalBalance     = (float) $accounts->sum('balance');
        $totalEquity      = (float) $accounts->sum('equity');
        $totalMargin      = (float) $accounts->sum('margin');
        $totalFreeMargin  = (float) $accounts->sum('free_margin');
        $totalFloatingPnl = (float) $accounts->sum('floating_pnl');
        $closedProfit     = (float) OrderHistory::whereIn('mt5_account_id', $accountIds)->sum('pnl');

        $floatingPct = $totalBalance > 0
            ? round(($totalFloatingPnl / $totalBalance) * 100, 2)
            : 0.0;

        $maxDrawdown  = (float) ($accounts->max('drawdown_percent') ?? 0);
        $maxAbsDdPct  = (float) ($accounts->max('max_abs_drawdown_pct') ?? 0);
        $maxEqDdPct   = (float) ($accounts->max('max_eq_drawdown_pct') ?? 0);

        // Overall profit curve = sum daily PnLs across all visible accounts
        $overallProfitSeries = $this->mergeAccountProfitSeries($profitSeriesByAcc);

        // Overall capital base = sum of each account's capital_base
        // (total_deposits if EA pushes it, else initial_balance fallback).
        // Overall ROI % = total closed profit / total capital base * 100
        // — i.e. realised return on deposited capital, ignoring floating PnL.
        $totalCapitalBase = (float) $accounts->sum(fn ($a) => (float) ($a->capital_base ?? 0));
        $overallRoiPct    = $totalCapitalBase > 0
            ? round(($closedProfit / $totalCapitalBase) * 100, 2)
            : null;

        $overall = [
            'account_count'        => $accounts->count(),
            'balance'              => $totalBalance,
            'equity'               => $totalEquity,
            'margin'               => $totalMargin,
            'free_margin'          => $totalFreeMargin,
            'floating_pnl'         => $totalFloatingPnl,
            'floating_pct'         => $floatingPct,
            'closed_profit'        => $closedProfit,
            'max_drawdown'         => $maxDrawdown,
            'max_abs_drawdown_pct' => $maxAbsDdPct,
            'max_eq_drawdown_pct'  => $maxEqDdPct,
            'capital_base'         => $totalCapitalBase,
            'roi_pct'              => $overallRoiPct,
            'profit_series'        => $overallProfitSeries,
        ];

        return Inertia::render('Dashboard', [
            'accounts'    => $accounts,
            'overall'     => $overall,
            'viewer_role' => $user->role,
        ]);
    }

    /**
     * Single-SQL batch loader: gets the latest N orders for ALL given accounts
     * via a ranked subquery. Avoids N+1.
     *
     * @return array<int, \Illuminate\Support\Collection>  keyed by mt5_account_id
     */
    private function loadOrdersBatched(string $modelClass, array $accountIds, string $timeCol, ?CarbonImmutable $minTime = null): array
    {
        if (empty($accountIds)) return [];

        /** @var \Illuminate\Database\Eloquent\Model $modelClass */
        $query = $modelClass::query()
            ->whereIn('mt5_account_id', $accountIds);

        if ($minTime !== null) {
            $query->where($timeCol, '>=', $minTime);
        }

        // Fetch — capped at ORDERS_PER_ACCOUNT_LIMIT × accountCount, then group.
        // For small N this is fine; for large N we could use window functions,
        // but in practice 50 × 10 accounts = 500 rows is trivial.
        $rows = $query
            ->orderBy('mt5_account_id')
            ->orderByDesc($timeCol)
            ->get()
            ->groupBy('mt5_account_id')
            ->map(fn ($group) => $group->take(self::ORDERS_PER_ACCOUNT_LIMIT));

        return $rows->all();
    }

    /**
     * Build cumulative profit-by-day curves for ALL accounts in a single GROUP BY.
     * No row-by-row PHP loop — scales to millions of trades.
     *
     * @return array<int, array<array{t:int, v:float, label:string, day_pnl:float}>>
     */
    private function aggregateProfitSeriesByAccount(array $accountIds, CarbonImmutable $cutoff): array
    {
        if (empty($accountIds)) return [];

        // GROUP BY (account, day in SGT). One row per (account_id, day).
        $rows = DB::table('orders_history')
            ->selectRaw("mt5_account_id,
                         DATE(CONVERT_TZ(closed_at, '+00:00', '+08:00')) AS day,
                         SUM(pnl) AS day_pnl")
            ->whereIn('mt5_account_id', $accountIds)
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', $cutoff)
            ->groupBy('mt5_account_id', 'day')
            ->orderBy('mt5_account_id')
            ->orderBy('day')
            ->get();

        // Cumulate per account
        $byAccount = [];
        foreach ($rows as $r) {
            $byAccount[(int) $r->mt5_account_id][] = [
                'day'     => $r->day,
                'day_pnl' => (float) $r->day_pnl,
            ];
        }

        $series = [];
        foreach ($byAccount as $accountId => $days) {
            $cumulative = 0.0;
            $points = [];
            foreach ($days as $d) {
                $cumulative += $d['day_pnl'];
                $points[] = [
                    't'       => strtotime($d['day']),
                    'v'       => round($cumulative, 2),
                    'label'   => $d['day'],
                    'day_pnl' => round($d['day_pnl'], 2),
                ];
            }
            $series[$accountId] = array_slice($points, -60);
        }

        return $series;
    }

    /**
     * Combine per-account profit series into one overall curve.
     * Buckets by day, sums across accounts, then cumulates.
     */
    private function mergeAccountProfitSeries(array $byAccount): array
    {
        if (empty($byAccount)) return [];

        // Sum day_pnl across all accounts per day
        $dailyTotals = [];
        foreach ($byAccount as $series) {
            foreach ($series as $point) {
                $dailyTotals[$point['label']] = ($dailyTotals[$point['label']] ?? 0) + $point['day_pnl'];
            }
        }
        ksort($dailyTotals);

        $cumulative = 0.0;
        $out = [];
        foreach ($dailyTotals as $day => $dayPnl) {
            $cumulative += $dayPnl;
            $out[] = [
                't'       => strtotime($day),
                'v'       => round($cumulative, 2),
                'label'   => $day,
                'day_pnl' => round($dayPnl, 2),
            ];
        }

        return array_slice($out, -60);
    }
}
