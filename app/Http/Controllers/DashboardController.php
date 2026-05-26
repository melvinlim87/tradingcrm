<?php

namespace App\Http\Controllers;

use App\Models\Mt5Account;
use App\Models\OrderHistory;
use App\Models\OrderOpen;
use App\Models\OrderPending;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DashboardController extends Controller
{
    public function index(\Illuminate\Http\Request $request): InertiaResponse
    {
        $user = $request->user();

        $accounts = $user->visibleAccountsQuery()
            ->with('creator:id,name,email,role')
            ->orderBy('account_number')
            ->get()
            ->map(function (Mt5Account $account) {
                $account->setRelation('openOrders', OrderOpen::where('mt5_account_id', $account->id)
                    ->orderByDesc('opened_at')
                    ->limit(50)
                    ->get());
                $account->setRelation('pendingOrders', OrderPending::where('mt5_account_id', $account->id)
                    ->orderByDesc('created_ea_at')
                    ->limit(50)
                    ->get());
                $account->setRelation('historyOrders', OrderHistory::where('mt5_account_id', $account->id)
                    ->orderByDesc('closed_at')
                    ->limit(50)
                    ->get());

                // PROFIT chart (cumulative closed PnL grouped by day) — won't twitch
                // every 10s like equity. Updates only when a trade closes.
                $account->setAttribute('profit_series', $this->buildProfitSeriesForAccounts([$account->id]));

                return $account;
            });

        $totalBalance     = (float) $accounts->sum('balance');
        $totalEquity      = (float) $accounts->sum('equity');
        $totalMargin      = (float) $accounts->sum('margin');
        $totalFreeMargin  = (float) $accounts->sum('free_margin');
        $totalFloatingPnl = (float) $accounts->sum('floating_pnl');
        $closedProfit     = (float) OrderHistory::whereIn(
            'mt5_account_id', $accounts->pluck('id')
        )->sum('pnl');

        $floatingPct = $totalBalance > 0
            ? round(($totalFloatingPnl / $totalBalance) * 100, 2)
            : 0.0;

        $maxDrawdown   = (float) ($accounts->max('drawdown_percent') ?? 0);
        $maxAbsDdPct   = (float) ($accounts->max('max_abs_drawdown_pct') ?? 0);
        $maxEqDdPct    = (float) ($accounts->max('max_eq_drawdown_pct') ?? 0);

        // Overall profit curve across all visible accounts
        $overallProfitSeries = $this->buildProfitSeriesForAccounts($accounts->pluck('id')->all());

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
            'profit_series'        => $overallProfitSeries,
        ];

        return Inertia::render('Dashboard', [
            'accounts' => $accounts,
            'overall'  => $overall,
            'viewer_role' => $user->role,
        ]);
    }

    /**
     * Cumulative closed-PnL curve grouped by close day.
     * Returns an array of points: [{ t: unix, v: cumulative_pnl, label: 'YYYY-MM-DD' }, ...].
     *
     * This is the "equity curve" most traders actually care about — based on
     * realized P&L only. Doesn't move with every tick like floating equity does.
     *
     * @return array<array{t:int, v:float, label:string, day_pnl:float}>
     */
    private function buildProfitSeriesForAccounts(array $accountIds): array
    {
        if (empty($accountIds)) return [];

        $trades = OrderHistory::whereIn('mt5_account_id', $accountIds)
            ->whereNotNull('closed_at')
            ->orderBy('closed_at')
            ->get(['closed_at', 'pnl']);

        if ($trades->isEmpty()) return [];

        // Bucket by day (Asia/Singapore) — sum each day's PnL
        $byDay = [];
        foreach ($trades as $t) {
            $day = optional($t->closed_at)
                ->setTimezone('Asia/Singapore')
                ->format('Y-m-d');
            if (! $day) continue;
            $byDay[$day] = ($byDay[$day] ?? 0) + (float) $t->pnl;
        }
        ksort($byDay);

        // Accumulate
        $cumulative = 0.0;
        $series = [];
        foreach ($byDay as $day => $dayPnl) {
            $cumulative += $dayPnl;
            $series[] = [
                't'       => strtotime($day),
                'v'       => round($cumulative, 2),
                'label'   => $day,
                'day_pnl' => round($dayPnl, 2),
            ];
        }

        // Keep last 60 trading days
        return array_slice($series, -60);
    }
}
