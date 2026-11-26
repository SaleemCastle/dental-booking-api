<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InventoryWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InventoryController extends Controller
{
    public function __construct(private readonly InventoryWorkflow $inventory) {}

    public function definitions(Request $request, string $resource): JsonResponse
    {
        return $this->respond($this->inventory->definitions($resource, $request->query(), $request->user()));
    }

    public function createDefinition(Request $request, string $resource): JsonResponse
    {
        return $this->respond($this->inventory->createDefinition($resource, $request->all(), $request->user()), 201);
    }

    public function movements(Request $request): JsonResponse
    {
        return $this->respond($this->inventory->movements($request->query(), $request->user()));
    }

    public function balances(Request $request): JsonResponse
    {
        return $this->respond($this->inventory->balances($request->query(), $request->user()));
    }

    public function record(Request $request): JsonResponse
    {
        return $this->recordMovement($request);
    }

    public function correct(Request $request, string $movement): JsonResponse
    {
        $id = filter_var($movement, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);

        return $this->recordMovement($request, $id);
    }

    private function recordMovement(Request $request, ?int $original = null): JsonResponse
    {
        $result = $this->inventory->record($request->all(), (string) $request->header('Idempotency-Key'), $request->user(), $original);

        return $this->respond($result, $result['replayed'] ? 200 : 201);
    }

    private function respond(mixed $data, int $status = 200): JsonResponse
    {
        return ApiResponse::success($data, statusCode: $status)->header('Cache-Control', 'private, no-store');
    }
}
