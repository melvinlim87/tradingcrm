<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_exports', function (Blueprint $table) {
            $table->decimal('bid', 18, 6)->nullable()->after('mime');
            $table->decimal('ask', 18, 6)->nullable()->after('bid');
            $table->unsignedTinyInteger('digits')->nullable()->after('ask');
        });
    }

    public function down(): void
    {
        Schema::table('chart_exports', function (Blueprint $table) {
            $table->dropColumn(['bid', 'ask', 'digits']);
        });
    }
};
