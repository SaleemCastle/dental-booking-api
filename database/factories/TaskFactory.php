<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'appointment_id' => null,
            'assignee_id' => User::factory(),
            'due_date' => fake()->dateTimeBetween('today', '+14 days')->format('Y-m-d'),
            'priority' => 'normal',
            'status' => 'pending',
            'description' => fake()->sentence(),
        ];
    }
}
