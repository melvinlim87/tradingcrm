<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mt5_account_id')
                ->nullable()
                ->constrained('mt5_accounts')
                ->cascadeOnDelete();
            $table->string('kind', 32)->default('drawdown');
            $table->decimal('value_at_trigger', 12, 4);
            $table->decimal('threshold', 12, 4);
            $table->text('message')->nullable();
            $table->boolean('telegram_sent')->default(false);
            $table->string('telegram_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['mt5_account_id', 'kind', 'sent_at'], 'ix_alert_logs_acc_kind_sent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_logs');
    }
};
