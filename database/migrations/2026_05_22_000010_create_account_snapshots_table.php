<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mt5_account_id')
                ->constrained('mt5_accounts')
                ->cascadeOnDelete();
            $table->decimal('balance', 18, 2);
            $table->decimal('equity', 18, 2);
            $table->decimal('margin', 18, 2)->default(0);
            $table->decimal('free_margin', 18, 2)->default(0);
            $table->decimal('margin_level', 18, 2)->nullable();
            $table->decimal('floating_pnl', 18, 2)->default(0);
            $table->decimal('drawdown_percent', 8, 4)->default(0);
            $table->timestamp('recorded_at')->index();
            $table->timestamps();

            $table->index(['mt5_account_id', 'recorded_at'], 'ix_snapshots_acc_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_snapshots');
    }
};
