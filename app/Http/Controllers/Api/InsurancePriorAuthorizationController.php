<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InsurancePriorAuthorizationEventResource;
use App\Http\Resources\InsurancePriorAuthorizationResource;
use App\Models\InsurancePriorAuthorization;
use App\Models\Patient;
use App\Services\InsurancePriorAuthorizationEvidenceWorkflow;
use App\Services\InsurancePriorAuthorizationWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class InsurancePriorAuthorizationController extends Controller
{
    public function __construct(private readonly InsurancePriorAuthorizationWorkflow $workflow, private readonly InsurancePriorAuthorizationEvidenceWorkflow $evidence) {}

    public function index(Request $r, Patient $patient)
    {
        $p = $this->workflow->listing($patient, $r->user(), $r->query());
        $p->setCollection($p->getCollection()->map(fn ($a) => new InsurancePriorAuthorizationResource($a)));

        return $this->response($p);
    }

    public function store(Request $r, Patient $patient)
    {
        return $this->response(['authorization' => new InsurancePriorAuthorizationResource($this->workflow->create($patient, $r->all(), (string) $r->header('Idempotency-Key'), $r->user()))], 201);
    }

    public function show(Request $r, Patient $patient, InsurancePriorAuthorization $authorization)
    {
        return $this->response(['authorization' => new InsurancePriorAuthorizationResource($this->workflow->show($patient, $authorization, $r->user()))]);
    }

    public function update(Request $r, Patient $patient, InsurancePriorAuthorization $authorization)
    {
        return $this->response(['authorization' => new InsurancePriorAuthorizationResource($this->workflow->updateDraft($patient, $authorization, $r->all(), $r->user()))]);
    }

    public function request(Request $r, Patient $patient, InsurancePriorAuthorization $authorization)
    {
        return $this->response(['authorization' => new InsurancePriorAuthorizationResource($this->workflow->recordRequest($patient, $authorization, $r->all(), $r->user()))]);
    }

    public function decision(Request $r, Patient $patient, InsurancePriorAuthorization $authorization)
    {
        return $this->response(['authorization' => new InsurancePriorAuthorizationResource($this->workflow->decision($patient, $authorization, $r->all(), $r->user()))]);
    }

    public function linkClaim(Request $r, Patient $patient, InsurancePriorAuthorization $authorization)
    {
        return $this->response(['authorization' => new InsurancePriorAuthorizationResource($this->workflow->linkClaim($patient, $authorization, $r->all(), $r->user()))]);
    }

    public function history(Request $r, Patient $patient, InsurancePriorAuthorization $authorization)
    {
        return $this->response(['events' => InsurancePriorAuthorizationEventResource::collection($this->workflow->history($patient, $authorization, $r->user()))]);
    }

    public function expire(Request $r, Patient $patient, InsurancePriorAuthorization $authorization)
    {
        return $this->response(['authorization' => new InsurancePriorAuthorizationResource($this->workflow->expire($patient, $authorization, $r->user()))]);
    }

    public function cancel(Request $r, Patient $patient, InsurancePriorAuthorization $authorization)
    {
        return $this->response(['authorization' => new InsurancePriorAuthorizationResource($this->workflow->cancel($patient, $authorization, $r->all(), $r->user()))]);
    }

    public function linkEvidence(Request $r, Patient $patient, InsurancePriorAuthorization $authorization)
    {
        return $this->response(['evidence' => $this->evidence->link($patient, $authorization, $r->all(), $r->user())], 201);
    }

    public function evidence(Request $r, Patient $patient, InsurancePriorAuthorization $authorization)
    {
        return $this->response(['evidence' => $this->evidence->listing($patient, $authorization, $r->user())]);
    }

    private function response(mixed $data,int $status = 200)
    {
        return ApiResponse::success($data,statusCode: $status)->header('Cache-Control','private, no-store');
    }
}
