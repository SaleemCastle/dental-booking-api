<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClinicNotification;
use App\Models\Invoice;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CommunicationController extends Controller
{
    public function patient(Request $request, \App\Models\Patient $patient)
    {
        $this->authorize('view', $patient);
        $this->authorizeHistory($request);

        return $this->history($request, 'patient_id', $patient->id);
    }

    public function appointment(Request $request, \App\Models\Appointment $appointment)
    {
        $this->authorize('view', $appointment);
        $this->authorizeHistory($request);

        return $this->history($request, 'appointment_id', $appointment->id);
    }

    private function history(Request $request, string $column, int $id)
    {
        $data = $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
        app(\App\Services\AuditLogService::class)->record('delivery_history_accessed', $column === 'patient_id' ? 'patients' : 'appointments', $id, $request->user()->id);

        return ApiResponse::success(ClinicNotification::where($column, $id)->whereNull('invoice_id')->with('deliveries')->orderByDesc('id')->paginate($data['per_page'] ?? 15))->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, Invoice $invoice)
    {
        $this->authorize('view', $invoice);
        $this->authorizeHistory($request);
        app(\App\Services\AuditLogService::class)->record('delivery_history_accessed', 'invoices', $invoice->id, $request->user()->id);
        $data = $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
        $notifications = ClinicNotification::where('invoice_id', $invoice->id)->with('deliveries')->orderByDesc('id')->paginate($data['per_page'] ?? 15);

        // Bodies, addresses, provider credentials and checkout links are never returned here.
        return ApiResponse::success($notifications)->header('Cache-Control', 'private, no-store');
    }

    private function authorizeHistory(Request $request): void
    {
        abort_unless(app(\App\Services\MessagingService::class)->permitted($request->user(), 'history_view'), 403);
    }
}
