<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_analyses', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 16);
            $table->date('week_start');
            $table->date('week_end');

            $table->json('charts')->nullable();
            $table->json('news_snapshot')->nullable();

            $table->enum('outlook', ['bullish', 'bearish', 'neutral'])->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->text('summary')->nullable();
            $table->json('market_structure')->nullable();
            $table->json('support_resistance')->nullable();
            $table->json('news_impact')->nullable();
            $table->json('trade_ideas')->nullable();

            $table->longText('prompt_used')->nullable();
            $table->json('raw_response')->nullable();
            $table->string('openrouter_model')->nullable();
            $table->unsignedInteger('tokens_used')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();

            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['symbol', 'week_start'], 'ix_analysis_symbol_week');
            $table->index(['status', 'created_at'], 'ix_analysis_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_analyses');
    }
};
