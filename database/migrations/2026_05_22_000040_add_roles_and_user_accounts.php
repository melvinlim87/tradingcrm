<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Role column on users
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['administrator', 'admin', 'user'])
                ->default('user')
                ->after('email');
        });

        // Seed admin@email.com → administrator
        \DB::table('users')->where('email', 'admin@email.com')->update(['role' => 'administrator']);

        // 2. Pivot table — used so a 'user' (read-only role) can be assigned
        //    one or more accounts to view. Administrators / admins don't need
        //    pivot rows; their scope is computed differently.
        Schema::create('mt5_account_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mt5_account_id')->constrained('mt5_accounts')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'mt5_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mt5_account_user');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
