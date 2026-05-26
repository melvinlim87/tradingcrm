<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── orders_history ──
        // For the dashboard `Closed Profit` aggregate + sparkline GROUP BY day:
        //   WHERE mt5_account_id IN (...) AND closed_at >= cutoff GROUP BY day
        // Existing (mt5_account_id, closed_at) already serves this — but adding
        // (closed_at, mt5_account_id) helps the inverse case (date-driven global
        // reports). Also add (mt5_account_id, symbol, closed_at) for the
        // per-symbol filtered history view.
        Schema::table('orders_history', function (Blueprint $table) {
            $table->index('closed_at', 'ix_orders_history_closed_at');
            $table->index(['mt5_account_id', 'symbol', 'closed_at'], 'ix_orders_history_acc_sym_closed');
        });

        // ── orders_open ──
        // Per-account open by symbol — used by the dashboard symbol filter
        Schema::table('orders_open', function (Blueprint $table) {
            $table->index(['mt5_account_id', 'symbol'], 'ix_orders_open_acc_sym');
        });

        // ── orders_pending ──
        Schema::table('orders_pending', function (Blueprint $table) {
            $table->index(['mt5_account_id', 'symbol'], 'ix_orders_pending_acc_sym');
        });

        // ── forex_news ──
        // HIGH-impact filter + currency + time-window is the most common query
        Schema::table('forex_news', function (Blueprint $table) {
            $table->index(['impact', 'currency', 'event_at'], 'ix_forex_news_impact_cur_event');
        });

        // ── account_snapshots ──
        // Recorded_at scans for dashboard sparklines (already exists, kept)
    }

    public function down(): void
    {
        Schema::table('orders_history', function (Blueprint $table) {
            $table->dropIndex('ix_orders_history_closed_at');
            $table->dropIndex('ix_orders_history_acc_sym_closed');
        });
        Schema::table('orders_open', function (Blueprint $table) {
            $table->dropIndex('ix_orders_open_acc_sym');
        });
        Schema::table('orders_pending', function (Blueprint $table) {
            $table->dropIndex('ix_orders_pending_acc_sym');
        });
        Schema::table('forex_news', function (Blueprint $table) {
            $table->dropIndex('ix_forex_news_impact_cur_event');
        });
    }
};
