<?php

namespace App\Services;

use App\Dental\DentalChartData;
use App\Models\ClinicalNote;
use App\Models\DentalChartRevision;
use App\Models\Dentist;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class DentalChartWorkflow
{
    public function __construct(private readonly AuditLogService $auditLogs) {}

    public function append(Patient $patient, array $input, User $actor): DentalChartRevision
    {
        Gate::forUser($actor)->authorize('create', DentalChartRevision::class);
        $data = DentalChartData::validate($input);

        return DB::transaction(function () use ($patient, $data, $actor): DentalChartRevision {
            // Serialize the first revision as well as subsequent revisions; locking
            // only an existing revision cannot protect an initially empty chart.
            $current = Patient::query()->lockForUpdate()->findOrFail($patient->id);
            if ($current->archived_at !== null) {
                throw ValidationException::withMessages(['patient' => ['Archived patient charts are read-only.']]);
            }
            Dentist::query()->lockForUpdate()->findOrFail($data['dentist_id']);
            if (isset($data['clinical_note_id'])) {
                $note = ClinicalNote::query()->lockForUpdate()->findOrFail($data['clinical_note_id']);
                if ($note->patient_id !== $current->id || $note->dentist_id !== (int) $data['dentist_id']) {
                    throw ValidationException::withMessages(['clinical_note_id' => ['The clinical note must match the patient and provider.']]);
                }
            }
            $latest = DentalChartRevision::where('patient_id', $current->id)->where('dentition', $data['dentition'])
                ->orderByDesc('version')->lockForUpdate()->first();
            $version = $latest?->version ?? 0;
            if ((int) $data['expected_version'] !== $version) {
                throw new ConflictHttpException('The chart changed. Reload it before submitting a new revision.');
            }

            $revision = DentalChartRevision::create([
                'patient_id' => $current->id, 'dentition' => $data['dentition'], 'version' => $version + 1,
                'dentist_id' => $data['dentist_id'], 'author_user_id' => $actor->id,
                'clinical_note_id' => $data['clinical_note_id'] ?? null,
                'teeth' => DentalChartData::normalize($data), 'reason' => $data['reason'], 'recorded_at' => now('UTC'),
            ]);
            $this->auditLogs->record('revision_created', 'dental_charts', $revision->id, $actor->id);

            return $revision;
        }, 3);
    }
}
