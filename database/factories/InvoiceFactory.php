<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Invoice $invoice): void {
            $invoice->items()->create(['description' => 'Invoice service', 'quantity' => 1, 'unit_price' => $invoice->amount_due, 'line_total' => $invoice->amount_due]);
        });
    }

    public function issued(): static
    {
        return $this->afterCreating(fn (Invoice $invoice) => $invoice->update(['status' => 'issued', 'issued_at' => now()]));
    }

    public function withPayment(string|int $amount): static
    {
        return $this->afterCreating(fn (Invoice $invoice) => Payment::factory()->for($invoice)->create(['amount' => $amount]));
    }

    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'appointment_id' => null,
            'invoice_number' => fake()->unique()->bothify('INV-####??'),
            'amount_due' => '100.00',
            'amount_paid' => '0.00',
            'status' => 'draft',
            'currency' => 'USD',
            'issued_at' => null,
            'due_date' => now()->addDays(30)->toDateString(),
        ];
    }
}
