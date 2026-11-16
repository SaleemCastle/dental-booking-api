<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InsuranceEstimateResource;
use App\Models\InsuranceEstimate;
use App\Models\Patient;
use App\Services\InsuranceEstimateWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class InsuranceEstimateController extends Controller
{
    public function __construct(private readonly InsuranceEstimateWorkflow $workflow) {}

    public function index(Request $request, Patient $patient)
    {
        $page = $this->workflow->listing($patient, $request->user(), $request->query());
        $page->setCollection($page->getCollection()->map(fn ($item) => new InsuranceEstimateResource($item)));
        return $this->response($page);
    }

    public function store(Request $request, Patient $patient)
    {
        return $this->response(['estimate' => new InsuranceEstimateResource($this->workflow->create($patient, $request->all(), $request->user()))], 201);
    }

    public function show(Request $request, Patient $patient, InsuranceEstimate $estimate)
    {
        return $this->response(['estimate' => new InsuranceEstimateResource($this->workflow->show($patient, $estimate, $request->user()))]);
    }

    public function supersede(Request $request, Patient $patient, InsuranceEstimate $estimate)
    {
        $this->workflow->show($patient, $estimate, $request->user());
        return $this->response(['estimate' => new InsuranceEstimateResource($this->workflow->create($patient, $request->all(), $request->user(), $estimate))], 201);
    }

    private function response(mixed $data, int $status = 200)
    {
        return ApiResponse::success($data, statusCode: $status)->header('Cache-Control', 'private, no-store');
    }
}
