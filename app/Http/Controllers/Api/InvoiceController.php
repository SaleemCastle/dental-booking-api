<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function index()
    {
        return ApiResponse::success([
            'invoices' => InvoiceResource::collection(Invoice::all()),
        ], 'Invoices retrieved.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'integer', 'exists:patient,id'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointment,id'],
            'invoice_number' => ['required', 'string', 'max:255', 'unique:invoices,invoice_number'],
            'amount_due' => ['required', 'numeric', 'min:0'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(['unpaid', 'partial', 'paid', 'refunded'])],
            'issued_at' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
        ]);

        $validated['amount_paid'] = $validated['amount_paid'] ?? 0;

        return ApiResponse::success([
            'invoice' => new InvoiceResource(Invoice::create($validated)),
        ], 'Invoice successfully created.', 201);
    }

    public function show(Invoice $invoice)
    {
        return ApiResponse::success([
            'invoice' => new InvoiceResource($invoice),
        ], 'Invoice retrieved.');
    }

    public function update(Request $request, Invoice $invoice)
    {
        $validated = $request->validate([
            'patient_id' => ['sometimes', 'required', 'integer', 'exists:patient,id'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointment,id'],
            'invoice_number' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('invoices', 'invoice_number')->ignore($invoice->id)],
            'amount_due' => ['sometimes', 'required', 'numeric', 'min:0'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'required', Rule::in(['unpaid', 'partial', 'paid', 'refunded'])],
            'issued_at' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
        ]);

        $invoice->update($validated);

        return ApiResponse::success([
            'invoice' => new InvoiceResource($invoice->refresh()),
        ], 'Invoice successfully updated.');
    }

    public function destroy(Invoice $invoice)
    {
        $invoice->delete();

        return ApiResponse::success(message: 'Invoice successfully deleted.');
    }
}
