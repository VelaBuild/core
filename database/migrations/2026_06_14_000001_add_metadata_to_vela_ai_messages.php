<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vela_ai_messages', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('tokens_used');
        });
    }

    public function down(): void
    {
        Schema::table('vela_ai_messages', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
