<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PatientWorkflow
{
    public function __construct(private readonly AuditLogService $auditLogs) {}

    public function create(array $data, User $actor): Patient
    {
        return DB::transaction(function () use ($data, $actor) {
            $patient = Patient::create($data);
            $this->auditLogs->record('created', 'patients', $patient->id, $actor->id);

            return $patient->refresh();
        }, 3);
    }

    public function update(Patient $patient, array $data, User $actor): Patient
    {
        return DB::transaction(function () use ($patient, $data, $actor) {
            $current = Patient::query()->lockForUpdate()->findOrFail($patient->id);
            $current->fill($data);

            if ($current->isDirty()) {
                $current->save();
                $this->auditLogs->record('updated', 'patients', $current->id, $actor->id);
            }

            return $current->refresh();
        }, 3);
    }

    public function archive(Patient $patient, User $actor): Patient
    {
        return DB::transaction(function () use ($patient, $actor) {
            $current = Patient::query()->lockForUpdate()->findOrFail($patient->id);

            if ($current->archived_at === null) {
                $current->archive($actor);
                $this->auditLogs->record('archived', 'patients', $current->id, $actor->id);
            }

            return $current->refresh();
        }, 3);
    }
}
