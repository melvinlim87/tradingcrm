<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forex_news', function (Blueprint $table) {
            // Matches MT5's News tab columns
            $table->string('subject')->nullable()->after('title');
            $table->string('category')->nullable()->after('subject');     // e.g. "Trading Central - Analyst Views"
            $table->longText('body_html')->nullable()->after('category'); // Full HTML preview
            $table->string('external_id', 128)->nullable()->after('mt5_event_id');

            // For broker-analyst news, source = 'mt5_broker_news' (vs 'forexfactory' / 'mt5')
            // Re-index source + add (source, event_at) for fast filtering
            $table->index(['source', 'event_at'], 'ix_forex_news_source_event');
            $table->index('external_id', 'ix_forex_news_external_id');
        });
    }

    public function down(): void
    {
        Schema::table('forex_news', function (Blueprint $table) {
            $table->dropIndex('ix_forex_news_source_event');
            $table->dropIndex('ix_forex_news_external_id');
            $table->dropColumn(['subject', 'category', 'body_html', 'external_id']);
        });
    }
};
