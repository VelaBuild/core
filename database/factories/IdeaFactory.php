<?php

namespace VelaBuild\Core\Database\Factories;

use VelaBuild\Core\Models\Idea;
use Illuminate\Database\Eloquent\Factories\Factory;

class IdeaFactory extends Factory
{
    protected $model = Idea::class;

    public function definition(): array
    {
        return [
            'name' => fake()->sentence(),
            'details' => fake()->paragraph(),
            'keyword' => fake()->word(),
            'status' => 'pending',
        ];
    }
}
