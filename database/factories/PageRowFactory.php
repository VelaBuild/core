<?php

namespace VelaBuild\Core\Database\Factories;

use VelaBuild\Core\Models\PageRow;
use Illuminate\Database\Eloquent\Factories\Factory;

class PageRowFactory extends Factory
{
    protected $model = PageRow::class;

    public function definition(): array
    {
        return [
            'name'         => fake()->word(),
            'css_class'    => null,
            'order_column' => 0,
            // page_id must be set when creating
        ];
    }
}
