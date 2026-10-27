<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use App\Tenancy\TenantRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        return $invoice ? $this->user()->can('update', $invoice) : $this->user()->can('create', Invoice::class);
    }

    public function rules(): array
    {
        return self::billingRules($this->route('invoice'));
    }

    public static function billingRules(?Invoice $invoice = null): array
    {
        $creating = $invoice === null;

        return [
            'patient_id' => $creating ? ['required', 'integer', TenantRules::exists('patient')] : ['missing'],
            'appointment_id' => $creating ? ['sometimes', 'nullable', 'integer', TenantRules::exists('appointment')] : ['missing'],
            'invoice_number' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255', TenantRules::unique('invoices', 'invoice_number')->ignore($invoice?->id)],
            'currency' => $creating ? ['required', 'string', 'regex:/\A[A-Z]{3}\z/'] : ['missing'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'status' => $creating ? ['missing'] : ['sometimes', 'required', Rule::in(['issued', 'void'])],
            'void_reason' => ['required_if:status,void', 'string', 'max:500', 'prohibited_unless:status,void'],
            'items' => $creating ? ['required', 'array', 'min:1', 'max:100'] : ['missing'],
            'items.*' => ['required', 'array:treatment_id,treatment_plan_item_id,description,unit_price,quantity'],
            'items.*.treatment_id' => ['sometimes', 'required', 'integer', TenantRules::exists('treatments'), 'prohibits:items.*.treatment_plan_item_id,items.*.description,items.*.unit_price'],
            'items.*.treatment_plan_item_id' => ['sometimes', 'required', 'integer', TenantRules::exists('treatment_plan_items'), 'prohibits:items.*.treatment_id,items.*.description,items.*.unit_price'],
            'items.*.description' => ['required_without_all:items.*.treatment_id,items.*.treatment_plan_item_id', 'string', 'max:255'],
            'items.*.unit_price' => ['required_without_all:items.*.treatment_id,items.*.treatment_plan_item_id', 'numeric', 'regex:/\A(0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/'],
            'items.*.quantity' => ['sometimes', 'required', 'integer', 'between:1,10000'],
            'tenant_id' => ['missing'],
            'amount_due' => ['missing'],
            'amount_paid' => ['missing'],
            'balance' => ['missing'],
            'issued_at' => ['missing'],
            'voided_at' => ['missing'],
        ];
    }
}
