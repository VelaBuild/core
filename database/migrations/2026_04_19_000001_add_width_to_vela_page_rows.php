<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vela_page_rows', function (Blueprint $table) {
            $table->string('width', 20)->default('contained')->after('padding');
        });
    }

    public function down(): void
    {
        Schema::table('vela_page_rows', function (Blueprint $table) {
            $table->dropColumn('width');
        });
    }
};
