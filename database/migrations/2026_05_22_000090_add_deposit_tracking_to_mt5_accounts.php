<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds deposit/withdrawal tracking so dashboards can show a TRUE ROI %
     * based on net capital deposited rather than the (often wrong) "balance
     * at first EA ping" proxy. EA scans HistoryDealsTotal() for
     * DEAL_TYPE_BALANCE deals each push and sums them.
     */
    public function up(): void
    {
        Schema::table('mt5_accounts', function (Blueprint $table) {
            $table->decimal('total_deposits',    18, 2)->default(0)->after('initial_balance');
            $table->decimal('total_withdrawals', 18, 2)->default(0)->after('total_deposits');
            $table->decimal('net_deposits',      18, 2)->nullable()->after('total_withdrawals');
        });
    }

    public function down(): void
    {
        Schema::table('mt5_accounts', function (Blueprint $table) {
            $table->dropColumn(['total_deposits', 'total_withdrawals', 'net_deposits']);
        });
    }
};
