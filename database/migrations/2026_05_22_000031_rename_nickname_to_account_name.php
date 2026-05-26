<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add the new column (pre-populated from existing nickname so we don't lose data)
        Schema::table('mt5_accounts', function (Blueprint $table) {
            $table->string('account_name')->nullable()->after('account_number');
        });

        \DB::statement('UPDATE mt5_accounts SET account_name = nickname WHERE account_name IS NULL');

        // 2. Drop the nickname column
        Schema::table('mt5_accounts', function (Blueprint $table) {
            $table->dropColumn('nickname');
        });
    }

    public function down(): void
    {
        Schema::table('mt5_accounts', function (Blueprint $table) {
            $table->string('nickname')->nullable()->after('account_number');
        });
        \DB::statement('UPDATE mt5_accounts SET nickname = account_name');
        Schema::table('mt5_accounts', function (Blueprint $table) {
            $table->dropColumn('account_name');
        });
    }
};
