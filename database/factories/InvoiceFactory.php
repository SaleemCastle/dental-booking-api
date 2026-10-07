<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        $amountDue = fake()->randomFloat(2, 75, 750);
        $amountPaid = fake()->randomFloat(2, 0, $amountDue);

        return [
            'patient_id' => Patient::factory(),
            'appointment_id' => Appointment::factory(),
            'invoice_number' => fake()->unique()->bothify('INV-####??'),
            'amount_due' => $amountDue,
            'amount_paid' => $amountPaid,
            'status' => $amountPaid <= 0 ? 'unpaid' : ($amountPaid < $amountDue ? 'partial' : 'paid'),
            'issued_at' => now(),
            'due_date' => now()->addDays(30)->toDateString(),
        ];
    }
}
