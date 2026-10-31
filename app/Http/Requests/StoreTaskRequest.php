<?php

namespace App\Http\Requests;

use App\Models\Task;
use App\Tenancy\TenantRules;
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
            'patient_id' => ['sometimes', 'nullable', 'integer', TenantRules::exists('patient')],
            'appointment_id' => ['sometimes', 'nullable', 'integer', TenantRules::exists('appointment')],
            'assignee_id' => ['required', 'integer', TenantRules::exists('users')],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'priority' => ['sometimes', 'required', Rule::in(Task::PRIORITIES)],
            'status' => ['sometimes', 'required', Rule::in(Task::STATUSES)],
            'description' => ['required', 'string', 'max:16000'],
            'patient_reminder_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'completed_at' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
        ];
    }
}
