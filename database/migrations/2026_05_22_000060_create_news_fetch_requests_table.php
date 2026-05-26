<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_fetch_requests', function (Blueprint $table) {
            $table->id();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed'])
                ->default('pending');
            $table->foreignId('requested_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedInteger('events_imported')->default(0);
            $table->unsignedInteger('events_updated')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_fetch_requests');
    }
};
