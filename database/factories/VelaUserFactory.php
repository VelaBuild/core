<?php

namespace VelaBuild\Core\Database\Factories;

use VelaBuild\Core\Models\VelaUser;
use Illuminate\Database\Eloquent\Factories\Factory;

class VelaUserFactory extends Factory
{
    protected $model = VelaUser::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'remember_token' => \Str::random(10),
        ];
    }
}
