<?php

namespace App\Services;

use App\Billing\Money;
use App\Http\Requests\InvoiceRequest;
use App\Http\Requests\RecordPaymentRequest;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BillingWorkflow
{
    public function __construct(private readonly AuditLogService $auditLogs) {}

    public function create(array $data, User $actor): Invoice
    {
        Gate::forUser($actor)->authorize('create', Invoice::class);
        $data = $this->validate($data, InvoiceRequest::billingRules());

        return DB::transaction(function () use ($data, $actor) {
            // Match treatment planning's patient -> plan -> item lock order.
            $patient = Patient::query()->lockForUpdate()->findOrFail($data['patient_id']);
            if ($patient->archived_at !== null) {
                $this->invalid('patient_id', 'Archived patients cannot receive new invoices.');
            }
            if (isset($data['appointment_id'])) {
                $appointment = Appointment::query()->lockForUpdate()->findOrFail($data['appointment_id']);
                if ($appointment->patient_id !== $patient->id) {
                    $this->invalid('appointment_id', 'The appointment must belong to the invoice patient.');
                }
            }
            $lines = [];
            $total = 0;
            foreach ($data['items'] as $index => $item) {
                $quantity = (int) ($item['quantity'] ?? 1);
                $treatmentId = null;
                $planItemId = null;
                if (isset($item['treatment_plan_item_id'])) {
                    $source = TreatmentPlanItem::query()->findOrFail($item['treatment_plan_item_id']);
                    $plan = TreatmentPlan::query()->lockForUpdate()->findOrFail($source->treatment_plan_id);
                    $source = TreatmentPlanItem::query()->lockForUpdate()->findOrFail($source->id);
                    if ($plan->patient_id !== $patient->id || $plan->status === 'cancelled' || $source->status === 'cancelled') {
                        $this->invalid("items.$index.treatment_plan_item_id", 'Choose a non-cancelled treatment plan item for this patient.');
                    }
                    $description = $source->treatment_name;
                    $price = Money::minor($source->estimated_price);
                    $treatmentId = $source->treatment_id;
                    $planItemId = $source->id;
                } elseif (isset($item['treatment_id'])) {
                    $source = Treatment::query()->lockForUpdate()->findOrFail($item['treatment_id']);
                    if (! $source->active) {
                        $this->invalid("items.$index.treatment_id", 'Choose an active catalog treatment.');
                    }
                    $description = $source->name;
                    $price = Money::minor($source->price);
                    $treatmentId = $source->id;
                } else {
                    $description = $item['description'];
                    $price = Money::minor($item['unit_price']);
                }
                $lineTotal = $price * $quantity;
                $total += $lineTotal;
                if ($total > Money::MAX_MINOR) {
                    $this->invalid('items', 'The invoice total cannot exceed 99999999.99.');
                }
                $lines[] = [
                    'treatment_id' => $treatmentId, 'treatment_plan_item_id' => $planItemId,
                    'description' => $description, 'quantity' => $quantity,
                    'unit_price' => Money::decimal($price), 'line_total' => Money::decimal($lineTotal),
                ];
            }
            $invoice = Invoice::create([
                'patient_id' => $patient->id, 'appointment_id' => $data['appointment_id'] ?? null,
                'invoice_number' => $data['invoice_number'], 'currency' => $data['currency'],
                'due_date' => $data['due_date'] ?? null, 'amount_due' => Money::decimal($total),
                'amount_paid' => '0.00', 'status' => 'draft',
            ]);
            foreach ($lines as $line) {
                $invoice->items()->create($line);
            }
            $this->auditLogs->record('created', 'invoices', $invoice->id, $actor->id);

            return $invoice->refresh()->load('items');
        }, 3);
    }

    public function update(Invoice $invoice, array $data, User $actor): Invoice
    {
        Gate::forUser($actor)->authorize('update', $invoice);
        $data = $this->validate($data, InvoiceRequest::billingRules($invoice));

        return DB::transaction(function () use ($invoice, $data, $actor) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $status = $data['status'] ?? $invoice->status;
            $action = 'updated';
            if ($invoice->status !== 'draft' && (array_key_exists('invoice_number', $data) || array_key_exists('due_date', $data))) {
                $this->invalid('status', 'Issued invoice details are immutable.');
            }
            if ($status === 'void' && in_array($invoice->status, ['draft', 'issued'], true)) {
                if ($invoice->payments()->exists()) {
                    $this->invalid('status', 'Invoices with payments require a refund workflow and cannot be voided.');
                }
                $invoice->voided_at = now();
                $invoice->void_reason = $data['void_reason'];
                $action = 'voided';
            } elseif ($status === 'issued' && $invoice->status === 'draft') {
                if (! $invoice->items()->exists()) {
                    $this->invalid('items', 'An invoice must have line items before issue.');
                }
                $invoice->issued_at = now();
                $status = Money::minor($invoice->amount_due) === 0 ? 'paid' : 'issued';
                $action = 'issued';
            } elseif ($invoice->status !== 'draft') {
                $this->invalid('status', 'Only draft invoices can be edited; issued invoices may only be voided before payment.');
            }
            $invoice->fill(array_intersect_key($data, array_flip(['invoice_number', 'due_date'])));
            $invoice->status = $status;
            if ($invoice->isDirty()) {
                $invoice->save();
                $this->auditLogs->record($action, 'invoices', $invoice->id, $actor->id);
            }

            return $invoice->refresh()->load('items');
        }, 3);
    }

    public function recordPayment(array $data, User $actor): Payment
    {
        Gate::forUser($actor)->authorize('create', Payment::class);
        $data = $this->validate($data, RecordPaymentRequest::paymentRules());

        return DB::transaction(function () use ($data, $actor) {
            // All payment writers serialize on the invoice, including retries.
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($data['invoice_id']);
            $existing = $invoice->payments()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                foreach (['amount', 'currency', 'payment_method', 'reference', 'receipt_number', 'paid_at'] as $field) {
                    $expected = $data[$field] ?? null;
                    $actual = $existing->getAttribute($field);
                    if ($field === 'amount') {
                        $expected = Money::decimal(Money::minor($expected));
                    } elseif ($field === 'paid_at') {
                        if (! isset($data['paid_at'])) {
                            continue;
                        }
                        $expected = \Illuminate\Support\Carbon::parse($expected)->utc()->format('Y-m-d H:i:s');
                        $actual = $actual->utc()->format('Y-m-d H:i:s');
                    }
                    if ($actual !== $expected) {
                        $this->invalid('idempotency_key', 'This key has already been used for a different payment.');
                    }
                }

                return $existing;
            }
            if (! in_array($invoice->status, ['issued', 'partially_paid'], true)) {
                $this->invalid('invoice_id', 'Payments require an issued invoice with an outstanding balance.');
            }
            if ($data['currency'] !== $invoice->currency) {
                $this->invalid('currency', 'Payment currency must match the invoice.');
            }
            $paid = $invoice->payments()->get()->sum(fn (Payment $payment) => Money::minor($payment->amount));
            $amount = Money::minor($data['amount']);
            $total = Money::minor($invoice->amount_due);
            if ($amount > $total - $paid) {
                $this->invalid('amount', 'Payment cannot exceed the outstanding balance.');
            }
            $payment = $invoice->payments()->create([
                ...$data, 'amount' => Money::decimal($amount), 'status' => 'paid',
                'paid_at' => isset($data['paid_at']) ? \Illuminate\Support\Carbon::parse($data['paid_at'])->utc() : now('UTC'),
            ]);
            $invoice->amount_paid = Money::decimal($paid + $amount);
            $invoice->status = $paid + $amount === $total ? 'paid' : 'partially_paid';
            $invoice->save();
            $this->auditLogs->record('recorded', 'payments', $payment->id, $actor->id);
            $this->auditLogs->record($invoice->status, 'invoices', $invoice->id, $actor->id);

            return $payment->refresh();
        }, 3);
    }

    private function validate(array $data, array $rules): array
    {
        $unknown = array_diff(array_keys($data), array_keys($rules));
        if ($unknown) {
            $this->invalid((string) reset($unknown), 'Unsupported billing field. Only documented fields may be supplied.');
        }

        return Validator::make($data, $rules)->validate();
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
