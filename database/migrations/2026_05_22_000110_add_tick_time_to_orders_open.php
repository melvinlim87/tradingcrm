<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-position tick timestamp pushed by the EA (v3.75+).
     * Lets the dashboard surface how fresh the displayed current_price
     * really is — important on illiquid pairs or weekend / pre-open
     * windows where MT5's POSITION_PRICE_CURRENT can lag the live
     * terminal bid/ask by tens of seconds.
     */
    public function up(): void
    {
        Schema::table('orders_open', function (Blueprint $table) {
            $table->timestamp('tick_time')->nullable()->after('current_price');
        });
    }

    public function down(): void
    {
        Schema::table('orders_open', function (Blueprint $table) {
            $table->dropColumn('tick_time');
        });
    }
};
