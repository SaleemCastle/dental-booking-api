<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\UpdateAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Services\AppointmentWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    private const RELATIONS = ['patient', 'dentist', 'treatment', 'invoice', 'room', 'chair'];

    public function index(Request $request)
    {
        $this->authorize('viewAny', Appointment::class);

        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $appointments = Appointment::query()
            ->with(self::RELATIONS)
            ->when(isset($validated['date']), function ($query) use ($validated) {
                $query->whereDate('appointment_date_time', $validated['date']);
            })
            ->orderBy('appointment_date_time')
            ->orderBy('id')
            ->get();

        return ApiResponse::success([
            'appointments' => AppointmentResource::collection($appointments),
        ], 'Appointments retrieved.');
    }

    public function store(StoreAppointmentRequest $request, AppointmentWorkflow $workflow)
    {
        $appointment = $workflow->create($request->validated());

        return ApiResponse::success([
            'appointment' => new AppointmentResource($appointment->load(self::RELATIONS)),
        ], 'Appointment successfully created.', 201);
    }

    public function show(Appointment $appointment)
    {
        $this->authorize('view', $appointment);

        return $this->appointmentResponse($appointment, 'Appointment retrieved.');
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment, AppointmentWorkflow $workflow)
    {
        return $this->appointmentResponse($workflow->update($appointment, $request->validated()));
    }

    public function destroy(Appointment $appointment)
    {
        $this->authorize('delete', $appointment);

        DB::transaction(function () use ($appointment) {
            $current = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);

            if ($current->clinicalNotes()->withTrashed()->exists()) {
                throw ValidationException::withMessages([
                    'appointment_id' => ['An appointment with clinical notes cannot be deleted.'],
                ]);
            }

            $current->delete();
        }, 3);

        return ApiResponse::success(message: 'Appointment successfully deleted.');
    }

    public function checkIn(Appointment $appointment, AppointmentWorkflow $workflow)
    {
        $this->authorize('update', $appointment);

        return $this->appointmentResponse($workflow->update($appointment, ['status' => 'checked_in']));
    }

    public function complete(Appointment $appointment, AppointmentWorkflow $workflow)
    {
        $this->authorize('update', $appointment);

        return $this->appointmentResponse($workflow->update($appointment, ['status' => 'completed']));
    }

    public function cancel(Request $request, Appointment $appointment, AppointmentWorkflow $workflow)
    {
        $this->authorize('update', $appointment);

        if (! $request->exists('cancellation_reason') && $request->exists('reason')) {
            $request->merge(['cancellation_reason' => $request->input('reason')]);
        }

        $validated = $request->validate([
            'cancellation_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return $this->appointmentResponse($workflow->update($appointment, ['status' => 'cancelled', ...$validated]));
    }

    private function appointmentResponse(Appointment $appointment, string $message = 'Appointment successfully updated.')
    {
        return ApiResponse::success([
            'appointment' => new AppointmentResource($appointment->load(self::RELATIONS)),
        ], $message);
    }
}
