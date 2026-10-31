<?php

namespace App\Services;

use App\Billing\Money;
use App\Models\Appointment;
use App\Models\Dentist;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ProviderAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ReportingService
{
    public function appointments(array $filters): array
    {
        $rows = $this->appointmentsQuery($filters)
            ->selectRaw('DATE(appointment_date_time) AS date, status, COUNT(*) AS total')
            ->groupByRaw('DATE(appointment_date_time), status')->orderBy('date')->get();
        $totals = $this->statusCounts($rows);

        return [
            'appointment_count' => array_sum($totals),
            'by_status' => $totals,
            'by_date' => $rows->groupBy('date')->map(fn (Collection $daily, string $date) => [
                'date' => $date,
                'appointment_count' => (int) $daily->sum('total'),
                'by_status' => $this->statusCounts($daily),
            ])->values()->all(),
        ];
    }

    public function treatments(array $filters): LengthAwarePaginator
    {
        // A completed appointment represents one occurrence of its assigned catalog treatment.
        return $this->appointmentsQuery($filters)->whereNotNull('treatment_id')
            ->selectRaw("treatment_id, COUNT(*) AS appointment_count, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count")
            ->groupBy('treatment_id')->orderBy('treatment_id')
            ->paginate($filters['per_page'] ?? 25)->through(fn (Appointment $row) => [
                'treatment_id' => (int) $row->treatment_id,
                'appointment_count' => (int) $row->appointment_count,
                'completed_count' => (int) $row->completed_count,
            ]);
    }

    public function providers(array $filters, bool $canViewAvailability): LengthAwarePaginator
    {
        $providers = Dentist::query()->select('id')
            ->when(isset($filters['dentist_id']), fn (Builder $query) => $query->whereKey($filters['dentist_id']))
            ->orderBy('id')->paginate($filters['per_page'] ?? 25);
        $ids = $providers->getCollection()->pluck('id');
        $activity = $this->appointmentsQuery($filters)->whereIn('dentist_id', $ids)
            ->selectRaw('dentist_id, status, COUNT(*) AS total, SUM(duration_minutes) AS minutes')
            ->groupBy('dentist_id', 'status')->get()->groupBy('dentist_id');
        $availability = $canViewAvailability
            ? ProviderAvailability::query()->whereIn('dentist_id', $ids)->get()->keyBy('dentist_id') : collect();

        [$start, $end] = $this->bounds($filters);
        // Bookings may cross midnight. The booking API limits duration to 1440 minutes.
        // Read only time, duration and provider, never patient IDs or clinical text.
        $bookings = $availability->isEmpty() ? collect() : Appointment::query()
            ->whereIn('dentist_id', $availability->keys())
            ->whereIn('status', [...Appointment::ACTIVE_STATUSES, 'completed'])
            ->where('appointment_date_time', '>=', $start->subDay())
            ->where('appointment_date_time', '<', $end)
            ->get(['dentist_id', 'appointment_date_time', 'duration_minutes'])->groupBy('dentist_id');

        return $providers->through(function (Dentist $provider) use ($activity, $availability, $bookings, $start, $end) {
            $rows = $activity->get($provider->id, collect());
            $counts = $this->statusCounts($rows);
            $schedule = $availability->get($provider->id);
            $capacity = $schedule === null ? null : $this->capacity($schedule, $start, $end);
            $occupied = $capacity === null ? null : $this->occupiedSeconds($capacity, $bookings->get($provider->id, collect()));
            $available = $capacity === null ? null : array_sum(array_map(fn (array $period) => $period[1] - $period[0], $capacity));

            return [
                'dentist_id' => (int) $provider->id,
                'appointment_count' => array_sum($counts),
                'by_status' => $counts,
                'booked_minutes' => (int) $rows->whereIn('status', [...Appointment::ACTIVE_STATUSES, 'completed'])->sum('minutes'),
                'completed_minutes' => (int) $rows->where('status', 'completed')->sum('minutes'),
                'available_minutes' => $available === null ? null : round($available / 60, 2),
                'occupied_minutes' => $occupied === null ? null : round($occupied / 60, 2),
                'utilization_percent' => $available ? round($occupied / $available * 100, 2) : null,
            ];
        });
    }

    public function billing(array $filters): array
    {
        [$start, $end] = $this->bounds($filters);
        $invoices = Invoice::query()->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->where('issued_at', '>=', $start)->where('issued_at', '<', $end);
        $payments = Payment::query()->where('status', 'paid')
            ->where('paid_at', '>=', $start)->where('paid_at', '<', $end);
        if (isset($filters['dentist_id'])) {
            // Attribution is through the invoice's appointment, never its patient or plan items.
            $invoices->whereHas('appointment', fn (Builder $query) => $query->where('dentist_id', $filters['dentist_id']));
            $payments->whereHas('invoice.appointment', fn (Builder $query) => $query->where('dentist_id', $filters['dentist_id']));
        }

        // Cast each rounded minor amount before SUM: no floating-point money arithmetic in PHP.
        $cast = match ($invoices->getConnection()->getDriverName()) {
            'mysql' => 'SIGNED',
            'pgsql' => 'BIGINT',
            default => 'INTEGER',
        };
        $totals = $invoices->selectRaw("currency, COUNT(*) AS invoice_count, SUM(CAST(ROUND(amount_due * 100) AS $cast)) AS invoiced_minor, SUM(CAST(ROUND(amount_paid * 100) AS $cast)) AS paid_minor")
            ->groupBy('currency')->get()->keyBy('currency');
        $collections = $payments->selectRaw("currency, COUNT(*) AS payment_count, SUM(CAST(ROUND(amount * 100) AS $cast)) AS collected_minor")
            ->groupBy('currency')->get()->keyBy('currency');

        return ['by_currency' => $totals->keys()->merge($collections->keys())->unique()->sort()->values()
            ->map(function (string $currency) use ($totals, $collections) {
                $invoices = $totals->get($currency);
                $payments = $collections->get($currency);

                return [
                    'currency' => $currency,
                    'invoice_count' => (int) ($invoices?->invoice_count ?? 0),
                    'invoiced_total' => Money::decimal((int) ($invoices?->invoiced_minor ?? 0)),
                    'paid_total' => Money::decimal((int) ($invoices?->paid_minor ?? 0)),
                    'outstanding_balance' => Money::decimal((int) ($invoices?->invoiced_minor ?? 0) - (int) ($invoices?->paid_minor ?? 0)),
                    'payment_count' => (int) ($payments?->payment_count ?? 0),
                    'collections_total' => Money::decimal((int) ($payments?->collected_minor ?? 0)),
                ];
            })->all()];
    }

    private function appointmentsQuery(array $filters): Builder
    {
        [$start, $end] = $this->bounds($filters);

        return Appointment::query()->where('appointment_date_time', '>=', $start)->where('appointment_date_time', '<', $end)
            ->when(isset($filters['dentist_id']), fn (Builder $query) => $query->where('dentist_id', $filters['dentist_id']));
    }

    private function bounds(array $filters): array
    {
        return [CarbonImmutable::parse($filters['date_from'], config('app.timezone'))->startOfDay(),
            CarbonImmutable::parse($filters['date_to'], config('app.timezone'))->startOfDay()->addDay()];
    }

    private function statusCounts(Collection $rows): array
    {
        return collect(Appointment::STATUSES)->mapWithKeys(fn (string $status) => [$status => (int) $rows->where('status', $status)->sum('total')])->all();
    }

    /** Effective working intervals, unioned and with all breaks subtracted. */
    private function capacity(ProviderAvailability $schedule, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $result = [];
        $exceptions = collect($schedule->exceptions)->keyBy('date');
        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $exception = $exceptions->get($day->toDateString());
            $hours = $exception !== null ? $exception['working_hours'] : collect($schedule->weekly_hours)->where('day_of_week', $day->dayOfWeek)->all();
            $breaks = $exception !== null ? $exception['breaks'] : collect($schedule->breaks)->where('day_of_week', $day->dayOfWeek)->all();
            $interval = function (array $period) use ($day) {
                return array_map(function (string $time) use ($day) {
                    [$hour, $minute] = array_map('intval', explode(':', $time));

                    return ($hour === 24 ? $day->addDay() : $day->setTime($hour, $minute))->getTimestamp();
                }, [$period['start_time'], $period['end_time']]);
            };
            $working = $this->union(array_map($interval, $hours));
            foreach (array_map($interval, $breaks) as [$breakStart, $breakEnd]) {
                $remaining = [];
                foreach ($working as [$from, $to]) {
                    if ($breakStart >= $to || $breakEnd <= $from) {
                        $remaining[] = [$from, $to];
                    } else {
                        if ($from < $breakStart) {
                            $remaining[] = [$from, $breakStart];
                        }
                        if ($breakEnd < $to) {
                            $remaining[] = [$breakEnd, $to];
                        }
                    }
                }
                $working = $remaining;
            }
            array_push($result, ...$working);
        }

        return $result;
    }

    private function occupiedSeconds(array $capacity, Collection $bookings): int
    {
        $intervals = $this->union($bookings->map(fn (Appointment $appointment) => [
            $appointment->appointment_date_time->getTimestamp(),
            $appointment->appointment_date_time->copy()->addMinutes($appointment->duration_minutes)->getTimestamp(),
        ])->all());
        $seconds = 0;
        // Both lists are disjoint and chronological; scan without a per-booking capacity loop.
        $index = 0;
        foreach ($capacity as [$from, $to]) {
            while (isset($intervals[$index]) && $intervals[$index][1] <= $from) {
                $index++;
            }
            for ($cursor = $index; isset($intervals[$cursor]) && $intervals[$cursor][0] < $to; $cursor++) {
                $seconds += max(0, min($to, $intervals[$cursor][1]) - max($from, $intervals[$cursor][0]));
            }
        }

        return $seconds;
    }

    private function union(array $intervals): array
    {
        usort($intervals, fn (array $left, array $right) => $left[0] <=> $right[0]);
        $result = [];
        foreach ($intervals as [$from, $to]) {
            $last = count($result) - 1;
            if ($last >= 0 && $result[$last][1] >= $from) {
                $result[$last][1] = max($result[$last][1], $to);
            } else {
                $result[] = [$from, $to];
            }
        }

        return $result;
    }
}
