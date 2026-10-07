<?php

namespace Database\Factories;

use App\Models\Dentist;
use App\Models\Patient;
use App\Models\Treatment;
use Illuminate\Database\Eloquent\Factories\Factory;

class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        $treatment = Treatment::factory();

        return [
            'patient_id' => Patient::factory(),
            'dentist_id' => Dentist::factory(),
            'treatment_id' => $treatment,
            'appointment_date_time' => fake()->dateTimeBetween('now', '+14 days'),
            'appointment_type' => fake()->randomElement(['Cleaning', 'Filling', 'Consultation']),
            'description' => fake()->sentence(),
            'status' => fake()->randomElement(['scheduled', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show']),
            'duration_minutes' => 30,
        ];
    }
}
