<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Treatment;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    private const ACTIVE_STATUSES = ['scheduled', 'checked_in', 'in_progress'];

    public function index(Request $request)
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $appointments = Appointment::query()
            ->with(['patient', 'dentist', 'treatment', 'invoice'])
            ->when(isset($validated['date']), function ($query) use ($validated) {
                $query->whereDate('appointment_date_time', $validated['date']);
            })
            ->orderBy('appointment_date_time')
            ->get();

        return ApiResponse::success([
            'appointments' => AppointmentResource::collection($appointments),
        ], 'Appointments retrieved.');
    }

    public function store(Request $request)
    {
        $normalized = $this->validatedAppointmentData($request);
        $this->ensureDentistIsAvailable($normalized);

        $appointment = Appointment::create($normalized);

        return ApiResponse::success([
            'appointment' => new AppointmentResource($appointment->load(['patient', 'dentist', 'treatment', 'invoice'])),
        ], 'Appointment successfully created.', 201);
    }

    public function show(Appointment $appointment)
    {
        return ApiResponse::success([
            'appointment' => new AppointmentResource($appointment->load(['patient', 'dentist', 'treatment', 'invoice'])),
        ], 'Appointment retrieved.');
    }

    public function update(Request $request, Appointment $appointment)
    {
        $normalized = $this->validatedAppointmentData($request, true);

        if ($this->changesSchedule($normalized)) {
            $this->ensureDentistIsAvailable(array_merge($appointment->only([
                'patient_id',
                'dentist_id',
                'treatment_id',
                'appointment_date_time',
                'appointment_type',
                'description',
                'status',
                'duration_minutes',
            ]), $normalized), $appointment);
        }

        $appointment->update($normalized);

        return ApiResponse::success([
            'appointment' => new AppointmentResource($appointment->refresh()->load(['patient', 'dentist', 'treatment', 'invoice'])),
        ], 'Appointment successfully updated.');
    }

    public function destroy(Appointment $appointment)
    {
        $appointment->delete();

        return ApiResponse::success(message: 'Appointment successfully deleted.');
    }

    public function checkIn(Appointment $appointment)
    {
        return $this->transition($appointment, 'checked_in', ['checked_in_at' => now()]);
    }

    public function complete(Appointment $appointment)
    {
        return $this->transition($appointment, 'completed', ['completed_at' => now()]);
    }

    public function cancel(Request $request, Appointment $appointment)
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string'],
        ]);

        return $this->transition($appointment, 'cancelled', [
            'cancelled_at' => now(),
            'cancellation_reason' => $validated['reason'] ?? null,
        ]);
    }

    private function validatedAppointmentData(Request $request, bool $partial = false): array
    {
        $request->merge([
            'appointment_date_time' => $request->input('appointment_date_time', $request->input('startsAt')),
            'appointment_type' => $request->input('appointment_type', $request->input('appointmentType')),
            'duration_minutes' => $request->input('duration_minutes', $request->input('durationMinutes')),
            'treatment_id' => $request->input('treatment_id', $request->input('treatmentId')),
            'patient_id' => $request->input('patient_id', $request->input('patientId')),
            'dentist_id' => $request->input('dentist_id', $request->input('dentistId')),
        ]);

        $prefix = $partial ? ['sometimes', 'required'] : ['required'];

        $validated = $request->validate([
            'patient_id' => [...$prefix, 'integer', 'exists:patient,id'],
            'dentist_id' => [...$prefix, 'integer', 'exists:dentist,id'],
            'treatment_id' => ['nullable', 'integer', 'exists:treatments,id'],
            'appointment_date_time' => [...$prefix, 'date'],
            'appointment_type' => [...$prefix, 'string', 'max:255'],
            'description' => [...$prefix, 'string'],
            'status' => [...$prefix, 'string', Rule::in(['scheduled', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show'])],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        if (! array_key_exists('duration_minutes', $validated) && isset($validated['treatment_id'])) {
            $validated['duration_minutes'] = Treatment::find($validated['treatment_id'])?->duration_minutes ?? 30;
        }

        if (! $partial) {
            $validated['duration_minutes'] = $validated['duration_minutes'] ?? 30;
        }

        return $validated;
    }

    private function ensureDentistIsAvailable(array $data, ?Appointment $ignore = null): void
    {
        $start = Carbon::parse($data['appointment_date_time']);
        $end = $start->copy()->addMinutes((int) ($data['duration_minutes'] ?? 30));

        $appointments = Appointment::query()
            ->where('dentist_id', $data['dentist_id'])
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereDate('appointment_date_time', $start->toDateString())
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->get(['id', 'appointment_date_time', 'duration_minutes']);

        $overlap = $appointments->first(function (Appointment $appointment) use ($start, $end) {
            $existingStart = Carbon::parse($appointment->appointment_date_time);
            $existingEnd = $existingStart->copy()->addMinutes((int) ($appointment->duration_minutes ?? 30));

            return $existingStart->lt($end) && $existingEnd->gt($start);
        });

        if ($overlap) {
            throw ValidationException::withMessages([
                'appointment_date_time' => ['The dentist already has an appointment during this time.'],
            ]);
        }
    }

    private function changesSchedule(array $data): bool
    {
        return collect(['dentist_id', 'appointment_date_time', 'duration_minutes'])
            ->contains(fn (string $key) => array_key_exists($key, $data));
    }

    private function transition(Appointment $appointment, string $status, array $extra = [])
    {
        $appointment->update(array_merge(['status' => $status], $extra));

        return ApiResponse::success([
            'appointment' => new AppointmentResource($appointment->refresh()->load(['patient', 'dentist', 'treatment', 'invoice'])),
        ], 'Appointment successfully updated.');
    }
}
