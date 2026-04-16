<?php

namespace VelaBuild\Core\Database\Factories;

use VelaBuild\Core\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PageFactory extends Factory
{
    protected $model = Page::class;

    public function definition(): array
    {
        $title = fake()->sentence(3);

        return [
            'title'            => $title,
            'slug'             => Str::slug($title) . '-' . fake()->unique()->numberBetween(1000, 9999),
            'locale'           => 'en',
            'status'           => 'published',
            'meta_title'       => fake()->sentence(),
            'meta_description' => fake()->sentence(),
            'order_column'     => 0,
        ];
    }
}
