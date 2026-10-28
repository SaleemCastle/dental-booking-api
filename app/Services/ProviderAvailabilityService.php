<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Dentist;
use App\Models\ProviderAvailability;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProviderAvailabilityService
{
    public function __construct(private readonly AuditLogService $auditLogs) {}

    public function replace(Dentist $dentist, array $data, User $actor): ProviderAvailability
    {
        return DB::transaction(function () use ($dentist, $data, $actor) {
            Dentist::query()->lockForUpdate()->findOrFail($dentist->id);
            $availability = ProviderAvailability::query()->where('dentist_id', $dentist->id)->lockForUpdate()->first()
                ?? new ProviderAvailability(['dentist_id' => $dentist->id]);
            $creating = ! $availability->exists;
            $availability->fill(collect($data)->only(['weekly_hours', 'breaks', 'exceptions'])->all());
            if ($creating || $availability->isDirty()) {
                $availability->save();
                $this->auditLogs->record($creating ? 'created' : 'updated', 'provider_availabilities', $availability->id, $actor->id);
            }

            return $availability;
        }, 3);
    }

    /** Called while holding the provider lock; use current reads after competing schedule writes. */
    public function ensureAvailable(Appointment $appointment): void
    {
        $availability = ProviderAvailability::query()->where('dentist_id', $appointment->dentist_id)->lockForUpdate()->first();
        if ($availability === null) {
            return;
        }

        $start = $appointment->appointment_date_time->copy()->setTimezone(config('app.timezone'));
        $end = $start->copy()->addMinutes($appointment->duration_minutes);
        for ($day = $start->copy()->startOfDay(); $day->lt($end); $day->addDay()) {
            $exception = collect($availability->exceptions)->firstWhere('date', $day->toDateString());
            $hours = $exception !== null ? $exception['working_hours']
                : collect($availability->weekly_hours)->where('day_of_week', $day->dayOfWeek)->all();
            $breaks = $exception !== null ? $exception['breaks']
                : collect($availability->breaks)->where('day_of_week', $day->dayOfWeek)->all();
            $segmentStart = $start->max($day);
            $segmentEnd = $end->min($day->copy()->addDay());

            $cursor = $segmentStart->copy();
            foreach (collect($hours)->sortBy('start_time') as $period) {
                [$from, $to] = $this->interval($day, $period);
                if ($from->gt($cursor)) {
                    break;
                }
                if ($to->gt($cursor)) {
                    $cursor = $to;
                }
                if ($cursor->gte($segmentEnd)) {
                    break;
                }
            }
            if ($cursor->lt($segmentEnd)) {
                $this->unavailable('The requested appointment is outside the provider working hours.');
            }
            foreach ($breaks as $period) {
                [$from, $to] = $this->interval($day, $period);
                if ($from->lt($segmentEnd) && $to->gt($segmentStart)) {
                    $this->unavailable('The provider is unavailable during the requested appointment.');
                }
            }
        }
    }

    private function interval(Carbon $day, array $period): array
    {
        return array_map(function (string $time) use ($day): Carbon {
            [$hour, $minute] = array_map('intval', explode(':', $time));

            return $hour === 24 ? $day->copy()->addDay() : $day->copy()->setTime($hour, $minute);
        }, [$period['start_time'], $period['end_time']]);
    }

    private function unavailable(string $message): never
    {
        throw ValidationException::withMessages([
            'dentist_id' => [$message],
            'appointment_date_time' => [$message],
        ]);
    }
}
