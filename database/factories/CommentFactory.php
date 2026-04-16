<?php

namespace VelaBuild\Core\Database\Factories;

use VelaBuild\Core\Models\Comment;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommentFactory extends Factory
{
    protected $model = Comment::class;

    public function definition(): array
    {
        return [
            'comment'   => fake()->sentence(),
            'status'    => 'approved',
            'useragent' => fake()->userAgent(),
            'ipaddress' => fake()->ipv4(),
        ];
    }
}
