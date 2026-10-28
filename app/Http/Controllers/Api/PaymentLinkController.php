<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentLinkResource;
use App\Models\Invoice;
use App\Models\PaymentLink;
use App\Services\PaymentLinkWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class PaymentLinkController extends Controller
{
    public function index(Request $request, Invoice $invoice)
    {
        $this->authorize('viewAny', PaymentLink::class);
        $this->authorize('viewPaymentLinks', $invoice);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $links = $invoice->paymentLinks()->orderByDesc('id')->paginate($data['per_page'] ?? 15);
        $links->setCollection($links->getCollection()->map(fn ($link) => new PaymentLinkResource($link)));

        return ApiResponse::success($links, 'Payment links retrieved.')->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Invoice $invoice, PaymentLinkWorkflow $workflow)
    {
        $link = $workflow->create($invoice, $request->all(), $request->user());

        return ApiResponse::success(['payment_link' => new PaymentLinkResource($link)], 'Payment link retrieved.', $link->wasRecentlyCreated ? 201 : 200)
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(PaymentLink $paymentLink)
    {
        $this->authorize('view', $paymentLink);

        return ApiResponse::success(['payment_link' => new PaymentLinkResource($paymentLink)], 'Payment link retrieved.')
            ->header('Cache-Control', 'private, no-store');
    }

    public function cancel(Request $request, PaymentLink $paymentLink, PaymentLinkWorkflow $workflow)
    {
        $link = $workflow->cancel($paymentLink, $request->all(), $request->user());

        return ApiResponse::success(['payment_link' => new PaymentLinkResource($link)], 'Payment link cancelled.')
            ->header('Cache-Control', 'private, no-store');
    }

    public function refresh(Request $request, PaymentLink $paymentLink, PaymentLinkWorkflow $workflow)
    {
        $link = $workflow->refresh($paymentLink, $request->all(), $request->user());

        return ApiResponse::success(['payment_link' => new PaymentLinkResource($link)], 'Payment link status refreshed.')
            ->header('Cache-Control', 'private, no-store');
    }
}
