<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentWebhookWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

final class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $endpoint, PaymentWebhookWorkflow $workflow)
    {
        abort_if(strlen($request->getContent()) > 1048576, 413);
        $workflow->receive($endpoint, $request->getContent(), $request->header('X-HandyPay-Signature', ''));

        return ApiResponse::success(['received' => true])->header('Cache-Control', 'private, no-store');
    }
}
