<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\BillingWorkflow;
use App\Support\ApiResponse;
use App\Tenancy\TenantRules;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Payment::class);
        $data = $request->validate([
            'invoice_id' => ['sometimes', 'integer', TenantRules::exists('invoices')],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $payments = Payment::query()
            ->when(isset($data['invoice_id']), fn ($query) => $query->where('invoice_id', $data['invoice_id']))
            ->orderByDesc('id')->paginate($data['per_page'] ?? 15);
        $payments->setCollection($payments->getCollection()->map(fn ($payment) => new PaymentResource($payment)));

        return ApiResponse::success($payments, 'Payments retrieved.')->header('Cache-Control', 'private, no-store');
    }

    public function store(RecordPaymentRequest $request, BillingWorkflow $workflow)
    {
        $payment = $workflow->recordPayment($request->all(), $request->user());

        return ApiResponse::success(['payment' => new PaymentResource($payment)], 'Payment recorded.', $payment->wasRecentlyCreated ? 201 : 200)
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Payment $payment)
    {
        $this->authorize('view', $payment);

        return ApiResponse::success(['payment' => new PaymentResource($payment)], 'Payment retrieved.')
            ->header('Cache-Control', 'private, no-store');
    }
}
