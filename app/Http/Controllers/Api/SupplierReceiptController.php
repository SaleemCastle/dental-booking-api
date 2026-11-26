<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SupplierReceivingWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SupplierReceiptController extends Controller
{
    public function __construct(private readonly SupplierReceivingWorkflow $receiving) {}

    public function index(Request $request): JsonResponse
    {
        return $this->respond($this->receiving->index($request->query(), $request->user()));
    }

    public function show(Request $request, string $supplierReceipt): JsonResponse
    {
        $id = filter_var($supplierReceipt, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);

        return $this->respond($this->receiving->show($id, $request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $result = $this->receiving->record($request->all(), (string) $request->header('Idempotency-Key'), $request->user());

        return $this->respond($result, $result['replayed'] ? 200 : 201);
    }

    private function respond(mixed $data, int $status = 200): JsonResponse
    {
        return ApiResponse::success($data, statusCode: $status)->header('Cache-Control', 'private, no-store');
    }
}
