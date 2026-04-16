<?php

namespace VelaBuild\Core\Database\Factories;

use VelaBuild\Core\Models\FormSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

class FormSubmissionFactory extends Factory
{
    protected $model = FormSubmission::class;

    public function definition(): array
    {
        return [
            'data'       => [
                'name'    => fake()->name(),
                'email'   => fake()->email(),
                'message' => fake()->paragraph(),
            ],
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'is_read'    => false,
            // page_id must be set when creating
        ];
    }
}
