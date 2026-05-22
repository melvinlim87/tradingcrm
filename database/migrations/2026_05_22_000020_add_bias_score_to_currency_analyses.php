<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currency_analyses', function (Blueprint $table) {
            $table->unsignedTinyInteger('bias_score')->nullable()->after('outlook');
        });
    }

    public function down(): void
    {
        Schema::table('currency_analyses', function (Blueprint $table) {
            $table->dropColumn('bias_score');
        });
    }
};
