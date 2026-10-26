<?php

namespace Database\Factories;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

class TreatmentPlanFactory extends Factory
{
    public function definition(): array
    {
        return ['patient_id' => Patient::factory(), 'title' => 'Restorative care', 'status' => 'draft'];
    }
}
