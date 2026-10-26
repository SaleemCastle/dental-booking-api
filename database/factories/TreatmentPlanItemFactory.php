<?php

namespace Database\Factories;

use App\Models\Dentist;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

class TreatmentPlanItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'treatment_plan_id' => TreatmentPlan::factory(),
            'treatment_id' => Treatment::factory(),
            'treatment_name' => 'Cleaning',
            'dentist_id' => Dentist::factory(),
            'catalog_price' => '125.50',
            'estimated_price' => '125.50',
            'status' => 'proposed',
            'sequence' => 1,
            'priority' => 'normal',
        ];
    }
}
