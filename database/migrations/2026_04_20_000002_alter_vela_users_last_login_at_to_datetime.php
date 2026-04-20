<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * last_login_at was a DATE column, which threw away the time-of-day.
 * Change to DATETIME so audit logs actually tell you when a user logged
 * in, not just which day.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vela_users', function (Blueprint $table) {
            $table->dateTime('last_login_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('vela_users', function (Blueprint $table) {
            $table->date('last_login_at')->nullable()->change();
        });
    }
};
