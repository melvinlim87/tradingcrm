<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chart_request_id')
                ->constrained('chart_requests')
                ->cascadeOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 8);
            $table->string('storage_path');
            $table->string('public_url')->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->string('mime', 64)->default('image/png');
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->unique(['chart_request_id', 'timeframe'], 'ux_chart_exports_req_tf');
            $table->index(['symbol', 'timeframe'], 'ix_chart_exports_symbol_tf');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_exports');
    }
};
