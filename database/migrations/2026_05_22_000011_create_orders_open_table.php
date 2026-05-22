<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders_open', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mt5_account_id')
                ->constrained('mt5_accounts')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('ticket');
            $table->string('symbol', 32);
            $table->enum('type', ['buy', 'sell']);
            $table->decimal('volume', 12, 2)->default(0);
            $table->decimal('open_price', 18, 6)->default(0);
            $table->decimal('current_price', 18, 6)->default(0);
            $table->decimal('sl', 18, 6)->default(0);
            $table->decimal('tp', 18, 6)->default(0);
            $table->decimal('profit', 14, 2)->default(0);
            $table->decimal('swap', 14, 2)->default(0);
            $table->decimal('pnl', 14, 2)->default(0);
            $table->unsignedBigInteger('magic')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();

            $table->unique(['mt5_account_id', 'ticket'], 'ux_orders_open_acc_ticket');
            $table->index('symbol');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders_open');
    }
};
