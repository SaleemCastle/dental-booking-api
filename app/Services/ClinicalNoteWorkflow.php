<?php

namespace App\Services;

use App\Events\ClinicalNoteChanged;
use App\Models\Appointment;
use App\Models\ClinicalNote;
use App\Models\Dentist;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicalNoteWorkflow
{
    public function __construct(private readonly AuditLogService $auditLogs) {}

    public function create(array $data, User $actor): ClinicalNote
    {
        return DB::transaction(function () use ($data, $actor) {
            $note = new ClinicalNote($data);
            $this->validateRelationships($note);
            $note->forceFill([
                'author_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $note->refresh();

            $this->auditLogs->record('created', 'clinical_notes', $note->id, $actor->id);
            ClinicalNoteChanged::dispatch($note->id, 'created', $actor->id, [], $note->attributesToArray());

            return $note;
        }, 3);
    }

    public function update(ClinicalNote $clinicalNote, array $data, User $actor): ClinicalNote
    {
        return DB::transaction(function () use ($clinicalNote, $data, $actor) {
            $note = ClinicalNote::query()->lockForUpdate()->findOrFail($clinicalNote->id);
            $before = $note->attributesToArray();
            $note->fill($data);
            if ($note->isDirty(['patient_id', 'appointment_id'])) {
                $documents = PatientDocument::withTrashed()->where('clinical_note_id', $note->id);
                if ($documents->exists() && ($note->isDirty('patient_id')
                    || ($note->appointment_id !== null && $documents->whereNotNull('appointment_id')->where('appointment_id', '<>', $note->appointment_id)->exists()))) {
                    throw ValidationException::withMessages(['clinical_note_id' => ['Linked documents must retain their patient and appointment relationships.']]);
                }
            }
            $this->validateRelationships($note);

            if (! $note->isDirty()) {
                return $note;
            }

            $note->forceFill(['updated_by_user_id' => $actor->id])->save();
            $note->refresh();
            $this->auditLogs->record('updated', 'clinical_notes', $note->id, $actor->id);
            ClinicalNoteChanged::dispatch($note->id, 'updated', $actor->id, $before, $note->attributesToArray());

            return $note;
        }, 3);
    }

    public function delete(ClinicalNote $clinicalNote, User $actor): void
    {
        DB::transaction(function () use ($clinicalNote, $actor) {
            $note = ClinicalNote::query()->lockForUpdate()->findOrFail($clinicalNote->id);
            $before = $note->attributesToArray();
            $note->forceFill([
                'updated_by_user_id' => $actor->id,
                'deleted_by_user_id' => $actor->id,
            ])->save();
            $note->delete();

            $this->auditLogs->record('deleted', 'clinical_notes', $note->id, $actor->id);
            ClinicalNoteChanged::dispatch($note->id, 'deleted', $actor->id, $before, $note->attributesToArray());
        }, 3);
    }

    private function validateRelationships(ClinicalNote $note): void
    {
        $appointment = $note->appointment_id !== null
            ? Appointment::query()->lockForUpdate()->findOrFail($note->appointment_id) : null;

        Patient::query()->lockForUpdate()->findOrFail($note->patient_id);
        Dentist::query()->lockForUpdate()->findOrFail($note->dentist_id);

        if ($appointment === null) {
            return;
        }

        $errors = [];

        if ($appointment->patient_id !== $note->patient_id) {
            $errors['patient_id'] = ['The patient must match the linked appointment.'];
        }

        if ($appointment->dentist_id !== $note->dentist_id) {
            $errors['dentist_id'] = ['The provider must match the linked appointment.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
