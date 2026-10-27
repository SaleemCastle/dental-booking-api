<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\BillingWorkflow;
use App\Support\ApiResponse;
use App\Tenancy\TenantRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);
        $data = $request->validate([
            'patient_id' => ['sometimes', 'integer', TenantRules::exists('patient')],
            'status' => ['sometimes', Rule::in(Invoice::STATUSES)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $invoices = Invoice::query()->with('items')
            ->when(isset($data['patient_id']), fn ($query) => $query->where('patient_id', $data['patient_id']))
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->orderByDesc('id')->paginate($data['per_page'] ?? 15);
        $invoices->setCollection($invoices->getCollection()->map(fn ($invoice) => new InvoiceResource($invoice)));

        return ApiResponse::success($invoices, 'Invoices retrieved.')->header('Cache-Control', 'private, no-store');
    }

    public function store(InvoiceRequest $request, BillingWorkflow $workflow)
    {
        return $this->invoiceResponse($workflow->create($request->all(), $request->user()), 'Invoice created.', 201);
    }

    public function show(Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        return $this->invoiceResponse($invoice->load('items'), 'Invoice retrieved.');
    }

    public function update(InvoiceRequest $request, Invoice $invoice, BillingWorkflow $workflow)
    {
        return $this->invoiceResponse($workflow->update($invoice, $request->all(), $request->user()), 'Invoice updated.');
    }

    private function invoiceResponse(Invoice $invoice, string $message, int $status = 200)
    {
        return ApiResponse::success(['invoice' => new InvoiceResource($invoice)], $message, $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
