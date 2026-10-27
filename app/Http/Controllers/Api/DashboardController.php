<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Dentist;
use App\Models\Invoice;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request)
    {
        $today = today();

        $todayAppointments = Appointment::query()
            ->with(['patient', 'dentist', 'treatment', 'invoice', 'room', 'chair'])
            ->whereDate('appointment_date_time', $today)
            ->orderBy('appointment_date_time')
            ->get();

        $canViewBilling = $request->user()->can('viewAny', Invoice::class);
        $billingTotals = $canViewBilling ? Invoice::query()->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->selectRaw('currency, SUM(amount_due) AS invoiced, SUM(amount_paid) AS collected')
            ->groupBy('currency')->orderBy('currency')->get()->map(fn ($row) => [
                'currency' => $row->currency,
                'unpaidInvoiceAmount' => round((float) $row->invoiced - (float) $row->collected, 2),
                'collectionRate' => (float) $row->invoiced > 0 ? round(((float) $row->collected / (float) $row->invoiced) * 100, 2) : 0,
            ])->all() : null;
        // Keep the existing scalar projection only when it has one denomination.
        $singleCurrency = $billingTotals !== null && count($billingTotals) <= 1;

        $staffWorklist = Dentist::query()
            ->withCount([
                'appointments as scheduled' => fn ($query) => $query
                    ->whereDate('appointment_date_time', $today)
                    ->where('status', 'scheduled'),
                'appointments as checkedIn' => fn ($query) => $query
                    ->whereDate('appointment_date_time', $today)
                    ->where('status', 'checked_in'),
                'appointments as completed' => fn ($query) => $query
                    ->whereDate('appointment_date_time', $today)
                    ->where('status', 'completed'),
            ])
            ->get()
            ->map(fn (Dentist $dentist) => [
                'dentistId' => $dentist->id,
                'dentistName' => trim($dentist->first_name.' '.$dentist->last_name),
                'scheduled' => $dentist->scheduled,
                'checkedIn' => $dentist->checkedIn,
                'completed' => $dentist->completed,
            ])
            ->values();

        return ApiResponse::success([
            'bookedToday' => $todayAppointments->count(),
            'waitingRoom' => $todayAppointments->where('status', 'checked_in')->count(),
            'treatmentsDone' => $todayAppointments->where('status', 'completed')->count(),
            'unpaidInvoiceAmount' => $singleCurrency ? ($billingTotals[0]['unpaidInvoiceAmount'] ?? 0) : null,
            'collectionRate' => $singleCurrency ? ($billingTotals[0]['collectionRate'] ?? 0) : null,
            'billingTotals' => $billingTotals,
            'todayAppointments' => AppointmentResource::collection($todayAppointments),
            'staffWorklist' => $staffWorklist,
        ], 'Dashboard summary retrieved.')->header('Cache-Control', 'private, no-store');
    }
}
