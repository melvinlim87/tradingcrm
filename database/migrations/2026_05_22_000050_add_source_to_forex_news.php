<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forex_news', function (Blueprint $table) {
            $table->string('source', 20)->default('forexfactory')->after('notes')->index();
            $table->unsignedBigInteger('mt5_event_id')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('forex_news', function (Blueprint $table) {
            $table->dropColumn(['source', 'mt5_event_id']);
        });
    }
};
