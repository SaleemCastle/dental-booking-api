<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InsuranceRemittanceAdviceResource;
use App\Http\Resources\InsuranceRemittanceLineResource;
use App\Services\InsuranceRemittanceAdviceWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class InsuranceRemittanceAdviceController extends Controller
{
    public function __construct(private readonly InsuranceRemittanceAdviceWorkflow $workflow) {}

    public function index(Request $request)
    {
        $page = $this->workflow->listing($request->query(), $request->user());
        $page->setCollection($page->getCollection()->map(fn ($advice) => new InsuranceRemittanceAdviceResource($advice)));

        return $this->response($page);
    }

    public function store(Request $request)
    {
        $result = $this->workflow->record($request->all(), (string) $request->header('Idempotency-Key'), $request->user());

        return $this->response([
            'advice' => new InsuranceRemittanceAdviceResource($result['advice']),
            'lines' => InsuranceRemittanceLineResource::collection($result['advice']->lines),
            'replayed' => $result['replayed'],
        ], $result['replayed'] ? 200 : 201);
    }

    public function show(Request $request, int $advice)
    {
        return $this->response(['advice' => new InsuranceRemittanceAdviceResource($this->workflow->show($advice, $request->user()))]);
    }

    public function lines(Request $request, int $advice)
    {
        $page = $this->workflow->lines($advice, $request->query(), $request->user());
        $page->setCollection($page->getCollection()->map(fn ($line) => new InsuranceRemittanceLineResource($line)));

        return $this->response($page);
    }

    public function correct(Request $request, int $advice)
    {
        $result = $this->workflow->correct($advice, $request->all(), (string) $request->header('Idempotency-Key'), $request->user());

        return $this->response([
            'advice' => new InsuranceRemittanceAdviceResource($result['advice']),
            'lines' => InsuranceRemittanceLineResource::collection($result['advice']->lines),
            'replayed' => $result['replayed'],
        ], $result['replayed'] ? 200 : 201);
    }

    private function response(mixed $data, int $status = 200)
    {
        return ApiResponse::success($data, statusCode: $status)->header('Cache-Control', 'private, no-store');
    }
}
