<?php

namespace VelaBuild\Core\Database\Factories;

use VelaBuild\Core\Models\Content;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ContentFactory extends Factory
{
    protected $model = Content::class;

    public function definition(): array
    {
        $title = fake()->sentence();

        return [
            'title' => $title,
            'slug' => Str::slug($title) . '-' . fake()->unique()->randomNumber(4),
            'type' => 'article',
            'keyword' => fake()->word(),
            'description' => fake()->paragraph(),
            'content' => fake()->paragraphs(3, true),
            'status' => 'published',
            'written_at' => now(),
            'published_at' => now(),
        ];
    }
}
