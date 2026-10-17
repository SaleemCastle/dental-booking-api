<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class RoomFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Room '.fake()->unique()->uuid()];
    }
}
