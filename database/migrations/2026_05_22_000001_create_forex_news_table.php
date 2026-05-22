<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forex_news', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('currency', 8)->index();
            $table->enum('impact', ['HIGH', 'MEDIUM', 'LOW', 'HOLIDAY'])->default('LOW');
            $table->string('forecast')->nullable();
            $table->string('previous')->nullable();
            $table->string('actual')->nullable();
            $table->text('measures')->nullable();
            $table->text('usual_effect')->nullable();
            $table->text('traders_care')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('event_at');
            $table->string('raw_date')->nullable();
            $table->timestamps();

            $table->unique(['title', 'event_at'], 'ux_forex_news_title_event');
            $table->index(['currency', 'event_at'], 'ix_forex_news_currency_event');
            $table->index('event_at', 'ix_forex_news_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forex_news');
    }
};
