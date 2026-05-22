<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders_pending', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mt5_account_id')
                ->constrained('mt5_accounts')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('ticket');
            $table->string('symbol', 32);
            $table->string('type', 32);
            $table->decimal('volume', 12, 2)->default(0);
            $table->decimal('entry', 18, 6)->default(0);
            $table->decimal('sl', 18, 6)->default(0);
            $table->decimal('tp', 18, 6)->default(0);
            $table->unsignedBigInteger('magic')->nullable();
            $table->timestamp('created_ea_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['mt5_account_id', 'ticket'], 'ux_orders_pending_acc_ticket');
            $table->index('symbol');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders_pending');
    }
};
