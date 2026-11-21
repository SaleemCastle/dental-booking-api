<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InsurerReceiptResource;
use App\Services\InsurerReceiptWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class InsurerReceiptController extends Controller
{
    public function __construct(private readonly InsurerReceiptWorkflow $workflow) {}

    public function index(Request $request)
    {
        $page = $this->workflow->listing($request->query(), $request->user());
        $page->setCollection($page->getCollection()->map(fn ($row) => new InsurerReceiptResource($row)));

        return $this->response($page);
    }

    public function store(Request $request)
    {
        $result = $this->workflow->record($request->all(), (string) $request->header('Idempotency-Key'), $request->user());

        return $this->response(['receipt' => new InsurerReceiptResource($result['receipt']->loadMissing('verification')), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    public function show(Request $request, int $receipt)
    {
        return $this->response(['receipt' => new InsurerReceiptResource($this->workflow->show($receipt, $request->user()))]);
    }

    public function verify(Request $request, int $receipt)
    {
        return $this->response(['receipt' => new InsurerReceiptResource($this->workflow->verify($receipt, $request->all(), $request->user()))], 201);
    }

    private function response(mixed $data, int $status = 200)
    {
        return ApiResponse::success($data, statusCode: $status)->header('Cache-Control', 'private, no-store');
    }
}
