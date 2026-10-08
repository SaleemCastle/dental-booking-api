<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DentistFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'contact_number' => fake()->phoneNumber(),
            'specialization' => fake()->randomElement(['General Dentistry', 'Orthodontics', 'Endodontics', 'Periodontics']),
        ];
    }
}
