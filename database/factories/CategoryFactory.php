<?php

namespace VelaBuild\Core\Database\Factories;

use VelaBuild\Core\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'icon' => null,
            'order_by' => fake()->numberBetween(1, 100),
        ];
    }
}
