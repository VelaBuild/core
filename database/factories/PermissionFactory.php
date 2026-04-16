<?php

namespace VelaBuild\Core\Database\Factories;

use VelaBuild\Core\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    public function definition(): array
    {
        return [
            'title' => fake()->unique()->word() . '_access',
        ];
    }
}
