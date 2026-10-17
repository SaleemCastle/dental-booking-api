<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChairFactory extends Factory
{
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'name' => 'Chair '.fake()->unique()->uuid(),
        ];
    }
}
