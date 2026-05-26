<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeCurrencyJob;
use App\Models\AlertLog;
use App\Models\Mt5Account;
use App\Models\OrderHistory;
use App\Models\OrderOpen;
use App\Models\OrderPending;
use App\Models\AccountSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * End-to-end walkthrough of every user flow + the EA push pipeline.
 * Wraps every test in a transaction so the seeded admin row survives.
 */
class WalkthroughTest extends TestCase
{
    use DatabaseTransactions;

    public function test_root_redirects_unauthenticated_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_root_redirects_authenticated_to_dashboard(): void
    {
        $admin = User::where('email', 'admin@email.com')->firstOrFail();
        $this->actingAs($admin)->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_admin_can_login(): void
    {
        $resp = $this->post('/login', [
            'email' => 'admin@email.com',
            'password' => 'Admin123456!',
        ]);
        $resp->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs(User::where('email', 'admin@email.com')->first());
    }

    public function test_dashboard_renders_overall_and_accounts(): void
    {
        $admin = User::where('email', 'admin@email.com')->firstOrFail();
        $resp = $this->actingAs($admin)->get('/dashboard');
        $resp->assertOk();
        $resp->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('overall')
            ->has('accounts')
        );
    }

    public function test_accounts_index_renders(): void
    {
        $admin = User::where('email', 'admin@email.com')->firstOrFail();
        $resp = $this->actingAs($admin)->get('/accounts');
        $resp->assertOk();
        $resp->assertInertia(fn ($page) => $page->component('Accounts/Index')->has('accounts'));
    }

    public function test_analysis_index_renders(): void
    {
        $admin = User::where('email', 'admin@email.com')->firstOrFail();
        $resp = $this->actingAs($admin)->get('/analysis');
        $resp->assertOk();
        $resp->assertInertia(fn ($page) => $page->component('Analysis/Index'));
    }

    public function test_analysis_generate_creates_records_and_redirects(): void
    {
        $admin = User::where('email', 'admin@email.com')->firstOrFail();

        // Fake the bus so the AnalyzeCurrencyJob (which would hit OpenRouter +
        // Yahoo Finance for real) does NOT execute during the test.
        Bus::fake();

        $this->actingAs($admin)
            ->post('/analysis/generate', ['symbol' => 'AUDUSD'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $analysis = \App\Models\CurrencyAnalysis::where('symbol', 'AUDUSD')
            ->where('status', 'pending')
            ->latest('id')
            ->first();
        $this->assertNotNull($analysis);

        // New flow: the controller dispatches AnalyzeCurrencyJob directly
        // (after-response, text-only). No ChartRequest is created anymore.
        Bus::assertDispatchedAfterResponse(
            AnalyzeCurrencyJob::class,
            fn (AnalyzeCurrencyJob $job) => $job->analysisId === $analysis->id,
        );
    }

    public function test_analysis_generate_validates_symbol(): void
    {
        $admin = User::where('email', 'admin@email.com')->firstOrFail();

        $this->actingAs($admin)
            ->post('/analysis/generate', ['symbol' => 'INVALID-123'])
            ->assertSessionHasErrors('symbol');
    }

    public function test_users_index_renders_for_administrator(): void
    {
        $admin = User::where('email', 'admin@email.com')->firstOrFail();
        $resp = $this->actingAs($admin)->get('/users');
        $resp->assertOk();
        $resp->assertInertia(fn ($page) => $page
            ->component('Users/Index')
            ->has('users')
            ->where('viewer_role', 'administrator'));
    }

    public function test_user_role_cannot_access_users_page(): void
    {
        $u = User::create([
            'name' => 'Viewer Vic',
            'email' => 'vic-test-only@test.com',
            'password' => bcrypt('Test123!'),
            'role' => 'user',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($u)->get('/users')->assertStatus(403);
    }

    public function test_administrator_creates_admin_then_admin_creates_user(): void
    {
        $administrator = User::where('email', 'admin@email.com')->firstOrFail();

        // Administrator creates an admin
        $this->actingAs($administrator)
            ->post('/users', [
                'name'  => 'Manager Mike',
                'email' => 'mike-test@test.com',
                'password' => 'Test1234!',
                'role'  => 'admin',
            ])->assertRedirect(route('users.index'));

        $mike = User::where('email', 'mike-test@test.com')->firstOrFail();
        $this->assertSame('admin', $mike->role);
        $this->assertSame($administrator->id, (int) $mike->created_by);

        // Admin can only create 'user' role users
        $this->actingAs($mike)
            ->post('/users', [
                'name' => 'Viewer Val',
                'email' => 'val-test@test.com',
                'password' => 'Test1234!',
                'role' => 'admin',     // not allowed for admin
            ])->assertSessionHasErrors('role');

        $this->actingAs($mike)
            ->post('/users', [
                'name' => 'Viewer Val',
                'email' => 'val-test@test.com',
                'password' => 'Test1234!',
                'role' => 'user',
            ])->assertRedirect(route('users.index'));

        $val = User::where('email', 'val-test@test.com')->firstOrFail();
        $this->assertSame('user', $val->role);
        $this->assertSame($mike->id, (int) $val->created_by);
    }

    public function test_account_crud(): void
    {
        $admin = User::where('email', 'admin@email.com')->firstOrFail();
        $this->actingAs($admin);

        // CREATE — nickname is gone; account_name now auto-populated from EA push
        $this->post('/accounts', [
            'account_number' => 9991231,
            'broker' => 'RS Finance',
            'drawdown_alert_threshold' => 5.0,
        ])->assertRedirect(route('accounts.index'));

        $acc = Mt5Account::where('account_number', 9991231)->first();
        $this->assertNotNull($acc);
        $this->assertEquals(5.0, (float) $acc->drawdown_alert_threshold);

        // UPDATE
        $this->put("/accounts/{$acc->id}", [
            'account_number' => 9991231,
            'broker' => 'RS Finance',
            'drawdown_alert_threshold' => 3.0,
        ])->assertRedirect(route('accounts.index'));

        $acc->refresh();
        $this->assertEquals(3.0, (float) $acc->drawdown_alert_threshold);

        // DELETE
        $this->delete("/accounts/{$acc->id}")->assertRedirect(route('accounts.index'));
        $this->assertNull(Mt5Account::where('account_number', 9991231)->first());
    }

    public function test_duplicate_account_number_is_rejected(): void
    {
        $admin = User::where('email', 'admin@email.com')->firstOrFail();
        $this->actingAs($admin);

        $this->post('/accounts', [
            'account_number' => 7777777,
            'broker' => 'RS Finance',
            'drawdown_alert_threshold' => 2.0,
        ])->assertRedirect();

        $this->post('/accounts', [
            'account_number' => 7777777,
            'broker' => 'RS Finance',
            'drawdown_alert_threshold' => 2.0,
        ])->assertSessionHasErrors('account_number');
    }

    public function test_ea_push_requires_token(): void
    {
        $this->postJson('/api/ea/push', ['account' => ['number' => 1]])
            ->assertStatus(401);
    }

    public function test_ea_push_rejects_unknown_account(): void
    {
        $resp = $this->postJson('/api/ea/push',
            ['account' => ['number' => 99999999]],
            ['Authorization' => 'Bearer ' . config('services.ea.token')]
        );
        $resp->assertStatus(404);
    }

    public function test_ea_push_full_payload_writes_everything(): void
    {
        $account = Mt5Account::create([
            'account_number' => 9991231,
            'account_name' => 'Live Test',
            'broker' => 'RS Finance',
            'drawdown_alert_threshold' => 5.0,
            'status' => 'offline',
        ]);

        $payload = [
            'account' => [
                'number' => 9991231, 'broker' => 'RS Finance', 'server' => 'RSF-Live',
                'currency' => 'USD', 'leverage' => 500,
                'balance' => 10000.00, 'equity' => 10250.50,
                'margin' => 500.00, 'free_margin' => 9750.50, 'margin_level' => 2050.10,
                'drawdown' => 0.0,
            ],
            'performance' => ['profitFactor' => 1.85, 'sharpeRatio' => 1.42, 'recoveryFactor' => 2.10],
            'overall' => ['EURUSD' => ['pnl' => 250.50, 'buy_layers' => 2, 'sell_layers' => 0, 'lot_per_1000' => '0.010', 'multiplier' => '-', 'step_pips' => '-']],
            'pending_orders' => [[
                'ticket' => 5001, 'symbol' => 'GBPUSD', 'type' => 'buy_limit', 'volume' => 0.10,
                'entry' => 1.2650, 'sl' => 1.2600, 'tp' => 1.2750, 'magic' => 112,
                'created_at' => '2026-05-22T14:00:00', 'expires_at' => null,
            ]],
            'positions' => [[
                'ticket' => 1001, 'symbol' => 'EURUSD', 'type' => 'buy', 'volume' => 0.10,
                'open_price' => 1.0850, 'current_price' => 1.0875,
                'sl' => 1.0800, 'tp' => 1.0900,
                'profit' => 25.00, 'swap' => -0.50, 'pnl' => 24.50, 'magic' => 112,
                'opened_at' => '2026-05-22T10:00:00',
            ]],
            'history' => [[
                'ticket' => 999, 'position_id' => 888, 'symbol' => 'GBPUSD', 'type' => 'sell',
                'volume' => 0.05, 'open_price' => 1.2700, 'close_price' => 1.2680,
                'sl' => 0, 'tp' => 0, 'profit' => 10.00, 'swap' => 0.0, 'commission' => -1.00,
                'pnl' => 9.00, 'magic' => 112,
                'opened_at' => '2026-05-21T10:00:00', 'closed_at' => '2026-05-21T18:30:00',
            ]],
            'reported_at' => '2026-05-22T15:00:00',
        ];

        $resp = $this->postJson('/api/ea/push', $payload, [
            'Authorization' => 'Bearer ' . config('services.ea.token'),
        ]);

        $resp->assertOk();

        $account->refresh();
        $this->assertEquals(10000.00, (float) $account->balance);
        $this->assertEquals(10250.50, (float) $account->equity);
        $this->assertEquals(250.50, (float) $account->floating_pnl);
        $this->assertEquals(500.00, (float) $account->margin);
        $this->assertSame('USD', $account->currency);
        $this->assertSame('online', $account->status);

        $this->assertSame(1, OrderOpen::where('mt5_account_id', $account->id)->count());
        $this->assertSame(1, OrderPending::where('mt5_account_id', $account->id)->count());
        $this->assertSame(1, OrderHistory::where('mt5_account_id', $account->id)->count());
        $this->assertSame(1, AccountSnapshot::where('mt5_account_id', $account->id)->count());

        // Position fields
        $pos = OrderOpen::where('mt5_account_id', $account->id)->first();
        $this->assertSame('EURUSD', $pos->symbol);
        $this->assertSame('buy', $pos->type);
        $this->assertEquals(0.10, (float) $pos->volume);

        // History
        $hist = OrderHistory::where('mt5_account_id', $account->id)->first();
        $this->assertSame('sell', $hist->type);
        $this->assertEquals(9.00, (float) $hist->pnl);
    }

    public function test_ea_push_replaces_open_positions_each_push(): void
    {
        $account = Mt5Account::create([
            'account_number' => 3000333,
            'account_name' => 'Replace Test',
            'broker' => 'RS Finance',
            'drawdown_alert_threshold' => 2.0,
        ]);

        $base = [
            'account' => ['number' => 3000333, 'broker' => 'RS Finance', 'balance' => 1000, 'equity' => 1000],
            'positions' => [['ticket' => 111, 'symbol' => 'EURUSD', 'type' => 'buy', 'volume' => 0.01, 'open_price' => 1.0, 'current_price' => 1.0, 'sl' => 0, 'tp' => 0, 'profit' => 0, 'swap' => 0, 'pnl' => 0]],
            'pending_orders' => [], 'history' => [],
        ];
        $this->postJson('/api/ea/push', $base, ['Authorization' => 'Bearer ' . config('services.ea.token')])->assertOk();
        $this->assertSame(1, OrderOpen::where('mt5_account_id', $account->id)->where('ticket', 111)->count());

        // 2nd push has a different ticket — the old one should be wiped
        $base['positions'] = [['ticket' => 222, 'symbol' => 'GBPUSD', 'type' => 'sell', 'volume' => 0.02, 'open_price' => 1.0, 'current_price' => 1.0, 'sl' => 0, 'tp' => 0, 'profit' => 0, 'swap' => 0, 'pnl' => 0]];
        $this->postJson('/api/ea/push', $base, ['Authorization' => 'Bearer ' . config('services.ea.token')])->assertOk();
        $this->assertSame(0, OrderOpen::where('mt5_account_id', $account->id)->where('ticket', 111)->count());
        $this->assertSame(1, OrderOpen::where('mt5_account_id', $account->id)->where('ticket', 222)->count());
    }

    public function test_ea_push_deduplicates_history_by_ticket(): void
    {
        $account = Mt5Account::create([
            'account_number' => 4000444,
            'account_name' => 'Dedupe Test',
            'broker' => 'RS Finance',
            'drawdown_alert_threshold' => 2.0,
        ]);

        $base = [
            'account' => ['number' => 4000444, 'broker' => 'RS Finance', 'balance' => 1000, 'equity' => 1000],
            'positions' => [], 'pending_orders' => [],
            'history' => [['ticket' => 555, 'symbol' => 'EURUSD', 'type' => 'buy', 'volume' => 0.01, 'open_price' => 1.0, 'close_price' => 1.1, 'profit' => 10, 'swap' => 0, 'commission' => 0, 'pnl' => 10]],
        ];
        // Push the same history twice; should still have only 1 row
        $this->postJson('/api/ea/push', $base, ['Authorization' => 'Bearer ' . config('services.ea.token')])->assertOk();
        $this->postJson('/api/ea/push', $base, ['Authorization' => 'Bearer ' . config('services.ea.token')])->assertOk();

        $this->assertSame(1, OrderHistory::where('mt5_account_id', $account->id)->count());
    }
}
