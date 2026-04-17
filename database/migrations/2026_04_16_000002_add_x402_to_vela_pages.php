<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vela_pages', function (Blueprint $table) {
            $table->boolean('x402_enabled')->nullable()->after('order_column');
            $table->string('x402_price_usd', 20)->nullable()->after('x402_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('vela_pages', function (Blueprint $table) {
            $table->dropColumn(['x402_enabled', 'x402_price_usd']);
        });
    }
};
