<?php

namespace VelaBuild\Core\Database\Factories;

use VelaBuild\Core\Models\Translation;
use Illuminate\Database\Eloquent\Factories\Factory;

class TranslationFactory extends Factory
{
    protected $model = Translation::class;

    public function definition(): array
    {
        return [
            'lang_code'    => 'en',
            'model_type'   => 'content',
            'model_key'    => fake()->word(),
            'translation'  => fake()->sentence(),
            'notes'        => null,
        ];
    }
}
