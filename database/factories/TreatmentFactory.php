<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TreatmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Cleaning', 'Filling', 'Root Canal', 'Whitening', 'Extraction']),
            'description' => fake()->sentence(),
            'duration_minutes' => fake()->randomElement([30, 45, 60, 90]),
            'price' => fake()->randomFloat(2, 50, 500),
        ];
    }
}
