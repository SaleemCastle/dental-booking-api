<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClinicNotification;
use App\Models\Invoice;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CommunicationController extends Controller
{
    public function index(Request $request, Invoice $invoice)
    {
        $this->authorize('view', $invoice);
        $data = $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
        $notifications = ClinicNotification::where('invoice_id', $invoice->id)->with('deliveries')->orderByDesc('id')->paginate($data['per_page'] ?? 15);

        // Bodies, addresses, provider credentials and checkout links are never returned here.
        return ApiResponse::success($notifications);
    }
}
