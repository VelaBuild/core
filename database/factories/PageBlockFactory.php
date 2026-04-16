<?php

namespace VelaBuild\Core\Database\Factories;

use VelaBuild\Core\Models\PageBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

class PageBlockFactory extends Factory
{
    protected $model = PageBlock::class;

    public function definition(): array
    {
        return [
            'column_index'  => 0,
            'column_width'  => 12,
            'order_column'  => 0,
            'type'          => 'text',
            'content'       => ['text' => fake()->paragraph()],
            'settings'      => [],
            // page_row_id must be set when creating
        ];
    }
}
