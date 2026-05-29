<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-editable free-text note per account.  Surfaced on the dashboard
     * (under the account name) so the trader monitoring the desk can leave
     * context for the team (e.g. "swing strategy / no Sunday gaps",
     * "USD-only", "client #4 funded May 1").
     */
    public function up(): void
    {
        Schema::table('mt5_accounts', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('drawdown_alert_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('mt5_accounts', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
