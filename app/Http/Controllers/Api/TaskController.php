<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Requests\UpdateTaskStatusRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\TaskWorkflow;
use App\Support\ApiResponse;
use App\Tenancy\TenantRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    private const RELATIONS = ['patient', 'appointment', 'assignee'];

    public function index(Request $request)
    {
        $this->authorize('viewAny', Task::class);

        $validated = $request->validate([
            'assigned_to_me' => ['sometimes', 'boolean'],
            'overdue' => ['sometimes', 'boolean'],
            'due_today' => ['sometimes', 'boolean'],
            'patient_id' => ['sometimes', 'integer', TenantRules::exists('patient')],
            'status' => ['sometimes', Rule::in(Task::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Task::query()->with(self::RELATIONS)
            ->when($request->boolean('assigned_to_me'), fn ($query) => $query->where('assignee_id', $request->user()->id))
            ->when($request->boolean('overdue'), fn ($query) => $query->whereIn('status', Task::OPEN_STATUSES)->where('due_date', '<', today()->toDateString()))
            ->when($request->boolean('due_today'), fn ($query) => $query->whereIn('status', Task::OPEN_STATUSES)->where('due_date', today()->toDateString()))
            ->when(isset($validated['patient_id']), fn ($query) => $query->where('patient_id', $validated['patient_id']))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']));

        $tasks = $query->orderBy('due_date')->orderBy('id')->paginate($validated['per_page'] ?? 15);

        return ApiResponse::success([
            'tasks' => TaskResource::collection($tasks->getCollection()),
        ], 'Tasks retrieved.', meta: [
            'pagination' => [
                'currentPage' => $tasks->currentPage(),
                'perPage' => $tasks->perPage(),
                'total' => $tasks->total(),
                'lastPage' => $tasks->lastPage(),
                'from' => $tasks->firstItem(),
                'to' => $tasks->lastItem(),
                'hasMorePages' => $tasks->hasMorePages(),
            ],
        ]);
    }

    public function store(StoreTaskRequest $request, TaskWorkflow $workflow)
    {
        $task = $workflow->create($request->validated(), $request->user());

        return ApiResponse::success([
            'task' => new TaskResource($task->load(self::RELATIONS)),
        ], 'Task successfully created.', 201);
    }

    public function show(Task $task)
    {
        $this->authorize('view', $task);

        return $this->taskResponse($task, 'Task retrieved.');
    }

    public function update(UpdateTaskRequest $request, Task $task, TaskWorkflow $workflow)
    {
        return $this->taskResponse($workflow->update($task, $request->validated(), $request->user()));
    }

    public function updateStatus(UpdateTaskStatusRequest $request, Task $task, TaskWorkflow $workflow)
    {
        return $this->taskResponse($workflow->update($task, $request->validated(), $request->user()));
    }

    public function destroy(Task $task)
    {
        $this->authorize('delete', $task);
        \Illuminate\Support\Facades\DB::transaction(function () use ($task) {
            $current = Task::lockForUpdate()->findOrFail($task->id);
            app(\App\Services\PatientEngagementService::class)->suppress('task_id', $current->id, auth()->id());
            $current->delete();
        });

        return ApiResponse::success(message: 'Task successfully deleted.');
    }

    private function taskResponse(Task $task, string $message = 'Task successfully updated.')
    {
        return ApiResponse::success([
            'task' => new TaskResource($task->load(self::RELATIONS)),
        ], $message);
    }
}
