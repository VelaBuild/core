<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vela_page_rows', function (Blueprint $table) {
            $table->string('background_color')->nullable()->after('css_class');
            $table->string('background_image')->nullable()->after('background_color');
        });

        Schema::table('vela_page_blocks', function (Blueprint $table) {
            $table->string('background_color')->nullable()->after('settings');
            $table->string('background_image')->nullable()->after('background_color');
        });
    }

    public function down(): void
    {
        Schema::table('vela_page_rows', function (Blueprint $table) {
            $table->dropColumn(['background_color', 'background_image']);
        });

        Schema::table('vela_page_blocks', function (Blueprint $table) {
            $table->dropColumn(['background_color', 'background_image']);
        });
    }
};
