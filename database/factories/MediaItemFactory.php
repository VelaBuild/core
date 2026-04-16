<?php

namespace VelaBuild\Core\Database\Factories;

use VelaBuild\Core\Models\MediaItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class MediaItemFactory extends Factory
{
    protected $model = MediaItem::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'alt_text' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'uploaded_by' => null,
        ];
    }
}
