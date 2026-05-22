<?php

namespace App\Http\Controllers;

use App\Models\Mt5Account;
use App\Models\TelegramTopic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class Mt5AccountController extends Controller
{
    public function index(): InertiaResponse
    {
        $accounts = Mt5Account::query()
            ->with('telegramTopic:id,mt5_account_id,name,thread_id')
            ->orderBy('account_number')
            ->get();

        $unboundTopics = TelegramTopic::query()
            ->whereNull('mt5_account_id')
            ->where('name', 'like', 'account_%')
            ->orderBy('name')
            ->get(['id', 'name', 'thread_id']);

        return Inertia::render('Accounts/Index', [
            'accounts' => $accounts,
            'unbound_topics' => $unboundTopics,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedPayload($request);

        DB::transaction(function () use ($data) {
            $account = Mt5Account::create($data + [
                'created_by' => request()->user()?->id,
            ]);

            if (! empty($data['telegram_topic_id'] ?? null)) {
                TelegramTopic::where('id', $data['telegram_topic_id'])
                    ->update(['mt5_account_id' => $account->id]);
            }
        });

        return redirect()
            ->route('accounts.index')
            ->with('success', "Account #{$data['account_number']} added.");
    }

    public function update(Request $request, Mt5Account $account): RedirectResponse
    {
        $data = $this->validatedPayload($request, $account->id);

        DB::transaction(function () use ($account, $data) {
            $account->update($data);

            if (array_key_exists('telegram_topic_id', $data)) {
                TelegramTopic::where('mt5_account_id', $account->id)
                    ->update(['mt5_account_id' => null]);

                if ($data['telegram_topic_id']) {
                    TelegramTopic::where('id', $data['telegram_topic_id'])
                        ->update(['mt5_account_id' => $account->id]);
                }
            }
        });

        return redirect()
            ->route('accounts.index')
            ->with('success', "Account #{$account->account_number} updated.");
    }

    public function destroy(Mt5Account $account): RedirectResponse
    {
        TelegramTopic::where('mt5_account_id', $account->id)
            ->update(['mt5_account_id' => null]);

        $accountNumber = $account->account_number;
        $account->delete();

        return redirect()
            ->route('accounts.index')
            ->with('success', "Account #{$accountNumber} removed.");
    }

    private function validatedPayload(Request $request, ?int $ignoreId = null): array
    {
        $accountNumberRule = 'required|integer|min:1|max:9999999999';
        $accountNumberRule .= $ignoreId
            ? "|unique:mt5_accounts,account_number,{$ignoreId}"
            : '|unique:mt5_accounts,account_number';

        return $request->validate([
            'account_number' => $accountNumberRule,
            'nickname' => 'nullable|string|max:100',
            'broker' => 'required|string|max:100',
            'drawdown_alert_threshold' => 'required|numeric|min:0.1|max:50',
            'telegram_topic_id' => 'nullable|exists:telegram_topics,id',
        ]);
    }
}
