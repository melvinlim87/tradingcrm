<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mt5_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_number')->unique();
            $table->string('nickname')->nullable();
            $table->string('broker')->default('RS Finance');
            $table->string('server')->nullable();
            $table->string('currency', 8)->nullable();
            $table->unsignedInteger('leverage')->nullable();

            // Latest snapshot (overwritten on every EA push)
            $table->decimal('balance', 18, 2)->default(0);
            $table->decimal('equity', 18, 2)->default(0);
            $table->decimal('margin', 18, 2)->default(0);
            $table->decimal('free_margin', 18, 2)->default(0);
            $table->decimal('margin_level', 18, 2)->nullable();
            $table->decimal('floating_pnl', 18, 2)->default(0);
            $table->decimal('drawdown_percent', 8, 4)->default(0);

            $table->decimal('drawdown_alert_threshold', 6, 2)->default(2.00);

            $table->enum('status', ['online', 'offline', 'disabled'])->default('offline');
            $table->timestamp('last_ping_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('last_ping_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mt5_accounts');
    }
};
