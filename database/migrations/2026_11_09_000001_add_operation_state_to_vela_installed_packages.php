<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Installing, updating and removing a package now run in the background, so
 * a package can be mid-operation or have failed. The status enum had no room
 * for that, and there was nowhere to keep the Composer output.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vela_installed_packages', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->change();
            $table->text('operation_log')->nullable()->after('installed_at');
            $table->string('operation_error', 500)->nullable()->after('operation_log');
            $table->timestamp('operation_started_at')->nullable()->after('operation_error');
            $table->timestamp('operation_finished_at')->nullable()->after('operation_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('vela_installed_packages', function (Blueprint $table) {
            $table->dropColumn(['operation_log', 'operation_error', 'operation_started_at', 'operation_finished_at']);
        });
    }
};
