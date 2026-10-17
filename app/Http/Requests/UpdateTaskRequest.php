<?php

namespace App\Http\Requests;

class UpdateTaskRequest extends StoreTaskRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('task'));
    }

    public function rules(): array
    {
        $rules = parent::rules();

        foreach (['assignee_id', 'due_date', 'description'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules;
    }
}
