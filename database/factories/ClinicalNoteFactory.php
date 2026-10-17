<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Dentist;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClinicalNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'appointment_id' => Appointment::factory(),
            'patient_id' => fn (array $attributes) => isset($attributes['appointment_id'])
                ? Appointment::findOrFail($attributes['appointment_id'])->patient_id : Patient::factory(),
            'dentist_id' => fn (array $attributes) => isset($attributes['appointment_id'])
                ? Appointment::findOrFail($attributes['appointment_id'])->dentist_id : Dentist::factory(),
            'note_text' => fake()->paragraph(),
            'author_user_id' => User::factory(),
            'updated_by_user_id' => fn (array $attributes) => $attributes['author_user_id'],
        ];
    }
}
