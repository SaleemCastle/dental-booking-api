<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PatientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'firstName' => fake()->firstName(),
            'lastName' => fake()->lastName(),
            'appointments' => fake()->randomElement(['Cleaning', 'Filling', 'Consultation']),
            'sex' => fake()->randomElement(['Female', 'Male', 'Other']),
            'streetAddress' => fake()->streetAddress(),
            'town' => fake()->citySuffix(),
            'city' => fake()->city(),
            'notes' => fake()->sentence(),
        ];
    }
}
