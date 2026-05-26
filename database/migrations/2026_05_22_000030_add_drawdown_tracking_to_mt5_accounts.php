<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mt5_accounts', function (Blueprint $table) {
            // Baseline: balance the very first time we saw this account.
            // Used to compute Max Absolute Drawdown.
            $table->decimal('initial_balance', 18, 2)->nullable()->after('balance');

            // Running peak equity ever observed. Used to compute Max Equity Drawdown
            // (peak-to-valley) without scanning the full snapshot history each push.
            $table->decimal('peak_equity', 18, 2)->nullable()->after('equity');

            // Max Absolute Drawdown % — how far below initial_balance equity has
            // ever fallen. Never decreases except by explicit reset.
            $table->decimal('max_abs_drawdown_pct', 8, 4)->default(0)
                ->after('drawdown_percent');

            // Max Equity Drawdown % — worst peak-to-valley drop. Never decreases.
            $table->decimal('max_eq_drawdown_pct', 8, 4)->default(0)
                ->after('max_abs_drawdown_pct');
        });
    }

    public function down(): void
    {
        Schema::table('mt5_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'initial_balance',
                'peak_equity',
                'max_abs_drawdown_pct',
                'max_eq_drawdown_pct',
            ]);
        });
    }
};
