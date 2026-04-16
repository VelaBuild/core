<?php

namespace VelaBuild\Core\Database\Factories;

use VelaBuild\Core\Models\VelaConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

class VelaConfigFactory extends Factory
{
    protected $model = VelaConfig::class;

    public function definition(): array
    {
        return [
            'key'   => fake()->unique()->word(),
            'value' => fake()->word(),
        ];
    }
}
