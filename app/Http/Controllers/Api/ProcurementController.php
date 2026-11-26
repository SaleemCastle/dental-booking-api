<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProcurementWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProcurementController extends Controller
{
    public function __construct(private readonly ProcurementWorkflow $procurement) {}

    public function suppliers(Request $request): JsonResponse
    {
        return $this->respond($this->procurement->suppliers($request->query(), $request->user()));
    }

    public function createSupplier(Request $request): JsonResponse
    {
        return $this->respond($this->procurement->saveSupplier($request->all(), $request->user()), 201);
    }

    public function updateSupplier(Request $request, string $supplier): JsonResponse
    {
        return $this->respond($this->procurement->saveSupplier($request->all(), $request->user(), $this->id($supplier)));
    }

    public function orders(Request $request): JsonResponse
    {
        return $this->respond($this->procurement->orders($request->query(), $request->user()));
    }

    public function order(Request $request, string $order): JsonResponse
    {
        return $this->respond($this->procurement->order($this->id($order), $request->user()));
    }

    public function createOrder(Request $request): JsonResponse
    {
        $result = $this->procurement->createOrder($request->all(), (string) $request->header('Idempotency-Key'), $request->user());

        return $this->respond($result, $result['replayed'] ? 200 : 201);
    }

    public function replaceOrder(Request $request, string $order): JsonResponse
    {
        return $this->respond($this->procurement->replaceOrder($this->id($order), $request->all(), $request->user()));
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
