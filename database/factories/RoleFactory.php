<?php

namespace VelaBuild\Core\Database\Factories;

use VelaBuild\Core\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'title' => fake()->word(),
        ];
    }
}
