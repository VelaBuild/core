<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A page's stylesheet now has to hold a copied section's own CSS, and MySQL's
 * TEXT tops out at 64KB — past which the column is truncated mid-rule and
 * takes the rest of the page's styling down with it. MEDIUMTEXT gives 16MB.
 *
 * SQLite has no such ceiling, so there is nothing to change there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('vela_pages') || !Schema::hasColumn('vela_pages', 'custom_css')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE `vela_pages` MODIFY `custom_css` MEDIUMTEXT NULL');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('vela_pages') || !Schema::hasColumn('vela_pages', 'custom_css')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE `vela_pages` MODIFY `custom_css` TEXT NULL');
        }
    }
};
