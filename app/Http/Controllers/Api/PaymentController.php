<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index()
    {
        return ApiResponse::success([
            'payments' => PaymentResource::collection(Payment::all()),
        ], 'Payments retrieved.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['unpaid', 'partial', 'paid', 'refunded'])],
            'paid_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        return ApiResponse::success([
            'payment' => new PaymentResource(Payment::create($validated)),
        ], 'Payment successfully created.', 201);
    }

    public function show(Payment $payment)
    {
        return ApiResponse::success([
            'payment' => new PaymentResource($payment),
        ], 'Payment retrieved.');
    }

    public function update(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'invoice_id' => ['sometimes', 'required', 'integer', 'exists:invoices,id'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', Rule::in(['unpaid', 'partial', 'paid', 'refunded'])],
            'paid_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $payment->update($validated);

        return ApiResponse::success([
            'payment' => new PaymentResource($payment->refresh()),
        ], 'Payment successfully updated.');
    }

    public function destroy(Payment $payment)
    {
        $payment->delete();

        return ApiResponse::success(message: 'Payment successfully deleted.');
    }
}
