<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'appointment_id' => $this->appointment_id,
            'assignee_id' => $this->assignee_id,
            'due_date' => $this->due_date->format('Y-m-d'),
            'priority' => $this->priority,
            'status' => $this->status,
            'description' => $this->description,
            'completed_at' => $this->completed_at,
            'patientName' => $this->whenLoaded('patient', fn () => $this->patient !== null
                ? trim($this->patient->firstName.' '.$this->patient->lastName) : null),
            'assigneeName' => $this->whenLoaded('assignee', fn () => $this->assignee?->name),
            'appointmentStartsAt' => $this->whenLoaded('appointment', fn () => $this->appointment?->appointment_date_time?->toISOString()),
            'is_overdue' => in_array($this->status, Task::OPEN_STATUSES, true) && $this->due_date->lt(today()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
