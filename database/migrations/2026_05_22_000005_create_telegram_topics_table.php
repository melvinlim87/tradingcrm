<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_topics', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->unsignedBigInteger('thread_id');
            $table->foreignId('mt5_account_id')
                ->nullable()
                ->constrained('mt5_accounts')
                ->nullOnDelete();
            $table->string('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_topics');
    }
};
