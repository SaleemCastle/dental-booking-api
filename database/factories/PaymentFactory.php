<?php

namespace Database\Factories;

use App\Billing\Money;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Payment $payment): void {
            $invoice = $payment->invoice;
            $paid = $invoice->payments()->get()->sum(fn (Payment $record) => Money::minor($record->amount));
            $invoice->update(['amount_paid' => Money::decimal($paid), 'status' => $paid === Money::minor($invoice->amount_due) ? 'paid' : 'partially_paid']);
        });
    }

    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory()->issued(),
            'amount' => '25.00',
            'payment_method' => fake()->randomElement(['cash', 'card', 'bank_transfer']),
            'status' => 'paid',
            'paid_at' => now(),
            'reference' => fake()->bothify('PAY-####??'),
            'currency' => 'USD',
            'idempotency_key' => fake()->uuid(),
        ];
    }
}
