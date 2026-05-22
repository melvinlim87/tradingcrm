<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountSnapshot;
use App\Models\AlertLog;
use App\Models\Mt5Account;
use App\Models\OrderHistory;
use App\Models\OrderOpen;
use App\Models\OrderPending;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class EaPushController extends Controller
{
    public function __construct(private readonly TelegramService $telegram)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        if (! is_array($payload) || empty($payload['account']['number'])) {
            return response()->json(['error' => 'Missing account.number'], 422);
        }

        $accountNumber = (int) $payload['account']['number'];

        $registered = Mt5Account::where('account_number', $accountNumber)->first();
        if (! $registered) {
            Log::warning('EA push rejected: unregistered account', [
                'account_number' => $accountNumber,
                'broker' => $payload['account']['broker'] ?? '?',
                'server' => $payload['account']['server'] ?? '?',
                'balance' => $payload['account']['balance'] ?? null,
                'positions_count' => is_array($payload['positions'] ?? null) ? count($payload['positions']) : 0,
            ]);
            return response()->json([
                'error' => "Account #{$accountNumber} is not registered. Add it in the Accounts page first.",
            ], 404);
        }

        try {
            DB::transaction(function () use ($registered, $payload) {
                $this->updateAccount($registered, $payload['account']);
                $this->writeSnapshot($registered, $payload['account']);
                $this->replaceOpenPositions($registered, $payload['positions'] ?? []);
                $this->replacePendingOrders($registered, $payload['pending_orders'] ?? []);
                $this->upsertHistory($registered, $payload['history'] ?? []);
            });

            $this->checkDrawdownAlert($registered->fresh());
        } catch (Throwable $e) {
            Log::error('EA push failed', [
                'account' => $accountNumber,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json([
            'status' => 'ok',
            'received_at' => now()->toIso8601String(),
        ]);
    }

    private function updateAccount(Mt5Account $account, array $a): void
    {
        $balance      = (float) ($a['balance'] ?? 0);
        $equity       = (float) ($a['equity'] ?? 0);
        $floatingPnl  = round($equity - $balance, 2);
        $drawdownPct  = $balance > 0 ? round((($balance - $equity) / $balance) * 100, 4) : 0;

        $account->update([
            'broker'           => $a['broker'] ?? $account->broker,
            'server'           => $a['server'] ?? $account->server,
            'currency'         => $a['currency'] ?? $account->currency,
            'leverage'         => (int) ($a['leverage'] ?? $account->leverage ?? 0) ?: $account->leverage,
            'balance'          => $balance,
            'equity'           => $equity,
            'margin'           => (float) ($a['margin'] ?? 0),
            'free_margin'      => (float) ($a['free_margin'] ?? 0),
            'margin_level'     => isset($a['margin_level']) ? (float) $a['margin_level'] : null,
            'floating_pnl'     => $floatingPnl,
            'drawdown_percent' => max(0, $drawdownPct),
            'status'           => 'online',
            'last_ping_at'     => now(),
        ]);
    }

    private function writeSnapshot(Mt5Account $account, array $a): void
    {
        $balance     = (float) ($a['balance'] ?? 0);
        $equity      = (float) ($a['equity'] ?? 0);
        $floatingPnl = round($equity - $balance, 2);
        $drawdownPct = $balance > 0 ? round((($balance - $equity) / $balance) * 100, 4) : 0;

        AccountSnapshot::create([
            'mt5_account_id'   => $account->id,
            'balance'          => $balance,
            'equity'           => $equity,
            'margin'           => (float) ($a['margin'] ?? 0),
            'free_margin'      => (float) ($a['free_margin'] ?? 0),
            'margin_level'     => isset($a['margin_level']) ? (float) $a['margin_level'] : null,
            'floating_pnl'     => $floatingPnl,
            'drawdown_percent' => max(0, $drawdownPct),
            'recorded_at'      => now(),
        ]);
    }

    private function replaceOpenPositions(Mt5Account $account, array $positions): void
    {
        OrderOpen::where('mt5_account_id', $account->id)->delete();

        foreach ($positions as $p) {
            OrderOpen::create([
                'mt5_account_id' => $account->id,
                'ticket'         => (int) ($p['ticket'] ?? 0),
                'symbol'         => (string) ($p['symbol'] ?? ''),
                'type'           => strtolower((string) ($p['type'] ?? 'buy')),
                'volume'         => (float) ($p['volume'] ?? 0),
                'open_price'     => (float) ($p['open_price'] ?? 0),
                'current_price'  => (float) ($p['current_price'] ?? 0),
                'sl'             => (float) ($p['sl'] ?? 0),
                'tp'             => (float) ($p['tp'] ?? 0),
                'profit'         => (float) ($p['profit'] ?? 0),
                'swap'           => (float) ($p['swap'] ?? 0),
                'pnl'            => (float) ($p['pnl'] ?? ($p['profit'] ?? 0) + ($p['swap'] ?? 0)),
                'magic'          => isset($p['magic']) ? (int) $p['magic'] : null,
                'opened_at'      => $this->parseDate($p['opened_at'] ?? null),
            ]);
        }
    }

    private function replacePendingOrders(Mt5Account $account, array $pending): void
    {
        OrderPending::where('mt5_account_id', $account->id)->delete();

        foreach ($pending as $p) {
            OrderPending::create([
                'mt5_account_id' => $account->id,
                'ticket'         => (int) ($p['ticket'] ?? 0),
                'symbol'         => (string) ($p['symbol'] ?? ''),
                'type'           => strtolower((string) ($p['type'] ?? 'unknown')),
                'volume'         => (float) ($p['volume'] ?? 0),
                'entry'          => (float) ($p['entry'] ?? 0),
                'sl'             => (float) ($p['sl'] ?? 0),
                'tp'             => (float) ($p['tp'] ?? 0),
                'magic'          => isset($p['magic']) ? (int) $p['magic'] : null,
                'created_ea_at'  => $this->parseDate($p['created_at'] ?? null),
                'expires_at'     => $this->parseDate($p['expires_at'] ?? null),
            ]);
        }
    }

    private function upsertHistory(Mt5Account $account, array $history): void
    {
        foreach ($history as $h) {
            $ticket = (int) ($h['ticket'] ?? 0);
            if ($ticket === 0) continue;

            OrderHistory::updateOrCreate(
                ['mt5_account_id' => $account->id, 'ticket' => $ticket],
                [
                    'position_id' => isset($h['position_id']) ? (int) $h['position_id'] : null,
                    'symbol'      => (string) ($h['symbol'] ?? ''),
                    'type'        => strtolower((string) ($h['type'] ?? 'buy')),
                    'volume'      => (float) ($h['volume'] ?? 0),
                    'open_price'  => (float) ($h['open_price'] ?? 0),
                    'close_price' => (float) ($h['close_price'] ?? 0),
                    'sl'          => (float) ($h['sl'] ?? 0),
                    'tp'          => (float) ($h['tp'] ?? 0),
                    'profit'      => (float) ($h['profit'] ?? 0),
                    'swap'        => (float) ($h['swap'] ?? 0),
                    'commission'  => (float) ($h['commission'] ?? 0),
                    'pnl'         => (float) ($h['pnl'] ?? 0),
                    'magic'       => isset($h['magic']) ? (int) $h['magic'] : null,
                    'opened_at'   => $this->parseDate($h['opened_at'] ?? null),
                    'closed_at'   => $this->parseDate($h['closed_at'] ?? null),
                ],
            );
        }
    }

    private function checkDrawdownAlert(Mt5Account $account): void
    {
        $dd = (float) $account->drawdown_percent;
        $threshold = (float) $account->drawdown_alert_threshold;

        if ($threshold <= 0 || $dd < $threshold) return;

        // Throttle: don't re-send the same alert within 1 hour
        $recent = AlertLog::where('mt5_account_id', $account->id)
            ->where('kind', 'drawdown')
            ->where('sent_at', '>=', now()->subHour())
            ->exists();

        if ($recent) return;

        $message = sprintf(
            "🚨 <b>Drawdown Alert</b>\nAccount: <code>#%d</code>\nDrawdown: <b>-%.2f%%</b> (threshold: %.2f%%)\nEquity: <b>%s</b>\nTime: %s GMT+8",
            $account->account_number,
            $dd,
            $threshold,
            number_format($account->equity, 2),
            now('Asia/Singapore')->format('Y-m-d H:i'),
        );

        $sent = false;
        $error = null;
        try {
            $sent = $this->telegram->sendToAccount($account->id, $message);
            if (! $sent) {
                $this->telegram->sendToTopic('risk_management', $message);
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        AlertLog::create([
            'mt5_account_id'   => $account->id,
            'kind'             => 'drawdown',
            'value_at_trigger' => $dd,
            'threshold'        => $threshold,
            'message'          => $message,
            'telegram_sent'    => $sent,
            'telegram_error'   => $error,
            'sent_at'          => now(),
        ]);
    }

    private function parseDate(?string $raw): ?Carbon
    {
        if (! $raw) return null;
        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }
}
