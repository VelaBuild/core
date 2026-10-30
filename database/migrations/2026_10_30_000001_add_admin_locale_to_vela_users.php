<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each account's own admin language (see AdminLanguage). Empty means the site's
 * default admin language, so existing accounts keep the admin they know.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('vela_users') || Schema::hasColumn('vela_users', 'admin_locale')) {
            return;
        }

        Schema::table('vela_users', function (Blueprint $table) {
            $table->string('admin_locale', 16)->nullable()->after('subscribe_newsletter');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('vela_users') || !Schema::hasColumn('vela_users', 'admin_locale')) {
            return;
        }

        Schema::table('vela_users', function (Blueprint $table) {
            $table->dropColumn('admin_locale');
        });
    }
};
