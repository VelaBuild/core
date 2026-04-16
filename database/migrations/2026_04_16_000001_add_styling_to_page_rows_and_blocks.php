<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vela_page_rows', function (Blueprint $table) {
            $table->string('text_color', 20)->nullable()->after('background_image');
            $table->string('text_alignment', 10)->nullable()->after('text_color');
            $table->string('padding', 50)->nullable()->after('text_alignment');
        });

        Schema::table('vela_page_blocks', function (Blueprint $table) {
            $table->string('text_color', 20)->nullable()->after('background_image');
            $table->string('text_alignment', 10)->nullable()->after('text_color');
            $table->string('padding', 50)->nullable()->after('text_alignment');
        });
    }

    public function down(): void
    {
        Schema::table('vela_page_rows', function (Blueprint $table) {
            $table->dropColumn(['text_color', 'text_alignment', 'padding']);
        });

        Schema::table('vela_page_blocks', function (Blueprint $table) {
            $table->dropColumn(['text_color', 'text_alignment', 'padding']);
        });
    }
};
