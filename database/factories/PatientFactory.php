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
            'emergency_contact_name' => fake()->name(),
            'emergency_contact_phone' => fake()->phoneNumber(),
            'emergency_contact_relationship' => fake()->randomElement(['Spouse', 'Parent', 'Sibling', 'Friend']),
            'allergies' => fake()->randomElements(['Penicillin', 'Latex', 'Aspirin'], fake()->numberBetween(0, 2)),
            'medications' => fake()->randomElements(['Ibuprofen', 'Metformin', 'Lisinopril'], fake()->numberBetween(0, 2)),
            'medical_alerts' => fake()->randomElements(['Diabetes', 'High blood pressure', 'Requires antibiotic prophylaxis'], fake()->numberBetween(0, 2)),
        ];
    }
}
