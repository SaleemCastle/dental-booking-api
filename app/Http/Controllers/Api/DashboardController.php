<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Dentist;
use App\Models\Invoice;
use App\Support\ApiResponse;

class DashboardController extends Controller
{
    public function summary()
    {
        $today = today();

        $todayAppointments = Appointment::query()
            ->with(['patient', 'dentist', 'treatment', 'invoice'])
            ->whereDate('appointment_date_time', $today)
            ->orderBy('appointment_date_time')
            ->get();

        $totalInvoiceAmount = (float) Invoice::sum('amount_due');
        $paidInvoiceAmount = (float) Invoice::sum('amount_paid');
        $collectionRate = $totalInvoiceAmount > 0
            ? round(($paidInvoiceAmount / $totalInvoiceAmount) * 100, 2)
            : 0;

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
            'unpaidInvoiceAmount' => (float) Invoice::query()
                ->whereIn('status', ['unpaid', 'partial'])
                ->selectRaw('COALESCE(SUM(amount_due - amount_paid), 0) as total')
                ->value('total'),
            'collectionRate' => $collectionRate,
            'todayAppointments' => AppointmentResource::collection($todayAppointments),
            'staffWorklist' => $staffWorklist,
        ], 'Dashboard summary retrieved.');
    }
}
