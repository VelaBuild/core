<?php

namespace VelaBuild\Core\Database\Seeders;

use Illuminate\Database\Seeder;

class VelaDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            VelaPermissionsSeeder::class,
            VelaRolesSeeder::class,
        ]);
    }
}
