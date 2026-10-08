<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Dentist;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClinicalNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'dentist_id' => Dentist::factory(),
            'appointment_id' => Appointment::factory(),
            'note_text' => fake()->paragraph(),
        ];
    }
}
