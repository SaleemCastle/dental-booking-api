<?php

namespace Database\Factories;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'amount' => fake()->randomFloat(2, 25, 500),
            'payment_method' => fake()->randomElement(['cash', 'card', 'bank_transfer']),
            'status' => 'paid',
            'paid_at' => now(),
            'reference' => fake()->bothify('PAY-####??'),
        ];
    }
}
