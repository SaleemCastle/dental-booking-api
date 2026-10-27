<?php

namespace App\Http\Requests;

use App\Models\Payment;
use App\Tenancy\TenantRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Payment::class);
    }

    public function rules(): array
    {
        return self::paymentRules();
    }

    public static function paymentRules(): array
    {
        return [
            'invoice_id' => ['required', 'integer', TenantRules::exists('invoices')],
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/\A(0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/'],
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'card', 'check', 'other'])],
            'paid_at' => ['sometimes', 'required', 'date', 'before_or_equal:now'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'receipt_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
        ];
    }
}
