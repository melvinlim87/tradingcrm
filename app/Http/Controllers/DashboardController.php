<?php

namespace App\Http\Controllers;

use App\Models\AccountSnapshot;
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

                // Sparkline data — last 60 snapshots of equity for this account
                $equitySeries = AccountSnapshot::where('mt5_account_id', $account->id)
                    ->orderByDesc('recorded_at')
                    ->limit(60)
                    ->get(['equity', 'recorded_at'])
                    ->reverse()
                    ->values()
                    ->map(fn ($s) => [
                        't' => $s->recorded_at->getTimestamp(),
                        'v' => (float) $s->equity,
                    ])
                    ->all();
                $account->setAttribute('equity_series', $equitySeries);

                return $account;
            });

        $totalBalance     = (float) $accounts->sum('balance');
        $totalEquity      = (float) $accounts->sum('equity');
        $totalMargin      = (float) $accounts->sum('margin');
        $totalFreeMargin  = (float) $accounts->sum('free_margin');
        $totalFloatingPnl = (float) $accounts->sum('floating_pnl');
        $closedProfit     = (float) OrderHistory::sum('pnl');

        $floatingPct = $totalBalance > 0
            ? round(($totalFloatingPnl / $totalBalance) * 100, 2)
            : 0.0;

        $maxDrawdown   = (float) ($accounts->max('drawdown_percent') ?? 0);
        $maxAbsDdPct   = (float) ($accounts->max('max_abs_drawdown_pct') ?? 0);
        $maxEqDdPct    = (float) ($accounts->max('max_eq_drawdown_pct') ?? 0);

        // Overall equity sparkline — sum equity per snapshot timestamp (bucket by minute)
        $overallSeries = $this->buildOverallEquitySeries($accounts);

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
            'equity_series'        => $overallSeries,
        ];

        return Inertia::render('Dashboard', [
            'accounts' => $accounts,
            'overall'  => $overall,
            'viewer_role' => $user->role,
        ]);
    }

    /**
     * Combine each account's latest equity series into a single overall curve.
     * Buckets by minute and sums.
     */
    private function buildOverallEquitySeries($accounts): array
    {
        $buckets = [];
        foreach ($accounts as $acc) {
            foreach ((array) ($acc->equity_series ?? []) as $point) {
                $minute = (int) (floor($point['t'] / 60) * 60);
                $buckets[$minute] = ($buckets[$minute] ?? 0) + $point['v'];
            }
        }
        ksort($buckets);
        $out = [];
        foreach ($buckets as $t => $v) {
            $out[] = ['t' => $t, 'v' => round($v, 2)];
        }
        return array_slice($out, -60);    // keep last 60 buckets
    }
}
