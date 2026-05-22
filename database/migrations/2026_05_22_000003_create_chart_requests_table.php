<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('currency_analysis_id')
                ->nullable()
                ->constrained('currency_analyses')
                ->cascadeOnDelete();
            $table->string('symbol', 16);
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed'])
                ->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'ix_chart_requests_status');
            $table->index('symbol', 'ix_chart_requests_symbol');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_requests');
    }
};
