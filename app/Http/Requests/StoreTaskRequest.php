<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Task::class);
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['sometimes', 'nullable', 'integer', 'exists:patient,id'],
            'appointment_id' => ['sometimes', 'nullable', 'integer', 'exists:appointment,id'],
            'assignee_id' => ['required', 'integer', 'exists:users,id'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'priority' => ['sometimes', 'required', Rule::in(Task::PRIORITIES)],
            'status' => ['sometimes', 'required', Rule::in(Task::STATUSES)],
            'description' => ['required', 'string', 'max:16000'],
            'completed_at' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
        ];
    }
}
