<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InsurerObligationResource;
use App\Services\InsurerObligationWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class InsurerObligationController extends Controller
{
    public function __construct(private readonly InsurerObligationWorkflow $workflow) {}

    public function index(Request $request)
    {
        $page = $this->workflow->listing($request->query(), $request->user());
        $page->setCollection($page->getCollection()->map(fn ($obligation) => new InsurerObligationResource($obligation)));

        return $this->response($page);
    }

    public function store(Request $request)
    {
        $result = $this->workflow->propose($request->all(), (string) $request->header('Idempotency-Key'), $request->user());

        return $this->response(['obligation' => new InsurerObligationResource($result['obligation']->loadMissing('approval')), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    public function show(Request $request, int $obligation)
    {
        return $this->response(['obligation' => new InsurerObligationResource($this->workflow->show($obligation, $request->user()))]);
    }

    public function approve(Request $request, int $obligation)
    {
        $result = $this->workflow->approve($obligation, $request->all(), (string) $request->header('Idempotency-Key'), $request->user());

        return $this->response(['obligation' => new InsurerObligationResource($result['obligation']->loadMissing('approval')), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    private function response(mixed $data, int $status = 200)
    {
        return ApiResponse::success($data, statusCode: $status)->header('Cache-Control', 'private, no-store');
    }
}
