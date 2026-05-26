<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forex_news', function (Blueprint $table) {
            // Additional metadata pulled from MqlCalendarEvent — surfaced
            // in the MT5 popup so users see real context, not "no data".
            $table->string('source_url', 500)->nullable()->after('external_id');
            $table->string('unit', 32)->nullable()->after('source_url');       // %, USD, jobs, etc.
            $table->string('sector', 64)->nullable()->after('unit');           // jobs, prices, gdp...
            $table->string('frequency', 32)->nullable()->after('sector');      // week, month, quarter
            $table->string('event_type', 32)->nullable()->after('frequency');  // indicator | event | holiday
        });
    }

    public function down(): void
    {
        Schema::table('forex_news', function (Blueprint $table) {
            $table->dropColumn(['source_url', 'unit', 'sector', 'frequency', 'event_type']);
        });
    }
};
