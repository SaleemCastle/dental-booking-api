<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('task'));
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(Task::STATUSES)],
            'patient_id' => ['missing'],
            'appointment_id' => ['missing'],
            'assignee_id' => ['missing'],
            'due_date' => ['missing'],
            'priority' => ['missing'],
            'description' => ['missing'],
            'patient_reminder_at' => ['missing'],
            'completed_at' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
        ];
    }
}
