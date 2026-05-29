<?php

namespace App\Http\Controllers;

use App\Models\Mt5Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class Mt5AccountController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();

        $accounts = $user->visibleAccountsQuery()
            ->with(['creator:id,name,email,role'])
            ->orderBy('account_number')
            ->get();

        return Inertia::render('Accounts/Index', [
            'accounts'    => $accounts,
            'viewer_role' => $user->role,
            'can_create'  => $user->canCreateAccounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeCreate($request);
        $data = $this->validatedPayload($request);

        Mt5Account::create($data + [
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('accounts.index')
            ->with('success', "Account #{$data['account_number']} added.");
    }

    public function update(Request $request, Mt5Account $account): RedirectResponse
    {
        $this->authorizeModify($request, $account);
        $data = $this->validatedPayload($request, $account->id);

        $account->update($data);

        return redirect()
            ->route('accounts.index')
            ->with('success', "Account #{$account->account_number} updated.");
    }

    public function destroy(Request $request, Mt5Account $account): RedirectResponse
    {
        $this->authorizeModify($request, $account);

        $accountNumber = $account->account_number;
        $account->delete();

        return redirect()
            ->route('accounts.index')
            ->with('success', "Account #{$accountNumber} removed.");
    }

    private function authorizeCreate(Request $request): void
    {
        abort_unless(
            $request->user()->canCreateAccounts(),
            403,
            'Your role does not permit creating accounts.',
        );
    }

    private function authorizeModify(Request $request, Mt5Account $account): void
    {
        $user = $request->user();
        if ($user->isAdministrator()) return;
        if ($user->isAdmin() && (int) $account->created_by === $user->id) return;
        abort(403, 'You can only edit accounts you created.');
    }

    private function validatedPayload(Request $request, ?int $ignoreId = null): array
    {
        $accountNumberRule = 'required|integer|min:1|max:9999999999';
        $accountNumberRule .= $ignoreId
            ? "|unique:mt5_accounts,account_number,{$ignoreId}"
            : '|unique:mt5_accounts,account_number';

        return $request->validate([
            'account_number' => $accountNumberRule,
            'broker' => 'required|string|max:100',
            'drawdown_alert_threshold' => 'required|numeric|min:0.1|max:50',
            'notes' => 'nullable|string|max:1000',
        ]);
        // Note: account_name is no longer a user input — the EA push will fill it
        // from MT5's ACCOUNT_NAME (broker-side account holder name).
        // Note: telegram_topic_id removed — Telegram alerts to be replaced by
        // WhatsApp notifications (planned, not yet implemented).
    }
}
