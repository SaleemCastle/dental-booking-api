<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EquipmentWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EquipmentController extends Controller
{
    public function __construct(private readonly EquipmentWorkflow $equipment) {}

    public function index(Request $request): JsonResponse
    {
        return $this->respond($this->equipment->index($request->query(), $request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->respond($this->equipment->create($request->all(), $request->user()), 201);
    }

    public function show(Request $request, string $equipment): JsonResponse
    {
        return $this->respond($this->equipment->show($this->id($equipment), $request->user()));
    }

    public function update(Request $request, string $equipment): JsonResponse
    {
        return $this->respond($this->equipment->update($this->id($equipment), $request->all(), $request->user()));
    }

    public function history(Request $request, string $equipment, string $kind): JsonResponse
    {
        return $this->respond($this->equipment->history($this->id($equipment), $kind, $request->query(), $request->user()));
    }

    public function record(Request $request, string $equipment, string $kind): JsonResponse
    {
        $result = $this->equipment->record($this->id($equipment), $kind, $request->all(), (string) $request->header('Idempotency-Key'), $request->user());

        return $this->respond($result, $result['replayed'] ? 200 : 201);
    }

    private function id(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);

        return $id;
    }

    private function respond(mixed $data, int $status = 200): JsonResponse
    {
        return ApiResponse::success($data, statusCode: $status)->header('Cache-Control', 'private, no-store');
    }
}
