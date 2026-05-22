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
    public function index(): InertiaResponse
    {
        $accounts = Mt5Account::query()
            ->orderBy('account_number')
            ->get()
            ->map(function (Mt5Account $account) {
                $account->setRelation('openOrders', OrderOpen::where('mt5_account_id', $account->id)
                    ->orderByDesc('opened_at')
                    ->limit(20)
                    ->get());
                $account->setRelation('pendingOrders', OrderPending::where('mt5_account_id', $account->id)
                    ->orderByDesc('created_ea_at')
                    ->limit(20)
                    ->get());
                $account->setRelation('historyOrders', OrderHistory::where('mt5_account_id', $account->id)
                    ->orderByDesc('closed_at')
                    ->limit(20)
                    ->get());
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

        $maxDrawdown = (float) ($accounts->max('drawdown_percent') ?? 0);

        $overall = [
            'account_count' => $accounts->count(),
            'balance'       => $totalBalance,
            'equity'        => $totalEquity,
            'margin'        => $totalMargin,
            'free_margin'   => $totalFreeMargin,
            'floating_pnl'  => $totalFloatingPnl,
            'floating_pct'  => $floatingPct,
            'closed_profit' => $closedProfit,
            'max_drawdown'  => $maxDrawdown,
        ];

        return Inertia::render('Dashboard', [
            'accounts' => $accounts,
            'overall'  => $overall,
        ]);
    }
}
