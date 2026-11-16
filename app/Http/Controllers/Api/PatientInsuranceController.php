<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientInsuranceCheckResource;
use App\Http\Resources\PatientInsurancePolicyRevisionResource;
use App\Http\Resources\PatientInsurerAssociationResource;
use App\Models\Patient;
use App\Models\PatientInsurerAssociation;
use App\Services\PatientInsuranceWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class PatientInsuranceController extends Controller
{
    public function __construct(private readonly PatientInsuranceWorkflow $workflow) {}

    public function index(Request $request, Patient $patient)
    {
        return $this->response($this->workflow->listing($patient, $request->query(), $request->user()));
    }

    public function store(Request $request, Patient $patient)
    {
        return $this->response(['association' => new PatientInsurerAssociationResource($this->workflow->create($patient, $request->all(), $request->user()))], 201);
    }

    public function show(Request $request, Patient $patient, PatientInsurerAssociation $association)
    {
        return $this->response(['association' => new PatientInsurerAssociationResource($this->workflow->show($patient, $association, $request->user()))]);
    }

    public function update(Request $request, Patient $patient, PatientInsurerAssociation $association)
    {
        return $this->response(['association' => new PatientInsurerAssociationResource($this->workflow->update($patient, $association, $request->all(), $request->user()))]);
    }

    public function history(Request $request, Patient $patient, PatientInsurerAssociation $association)
    {
        return $this->response($this->workflow->history($patient, $association, $request->query(), $request->user()));
    }

    public function policies(Request $request, Patient $patient, PatientInsurerAssociation $association)
    {
        return $this->response($this->workflow->policyRevisions($patient, $association, $request->query(), $request->user()));
    }

    public function revisePolicy(Request $request, Patient $patient, PatientInsurerAssociation $association)
    {
        return $this->response(['revision' => new PatientInsurancePolicyRevisionResource($this->workflow->revisePolicy($patient, $association, $request->all(), $request->user()))], 201);
    }

    public function identifiers(Request $request, Patient $patient, PatientInsurerAssociation $association, int $revision)
    {
        return $this->response(['identifiers' => $this->workflow->revealPolicy($patient, $association, $revision, $request->user())]);
    }

    public function checks(Request $request, Patient $patient, PatientInsurerAssociation $association)
    {
        return $this->response($this->workflow->checks($patient, $association, $request->query(), $request->user()));
    }

    public function recordCheck(Request $request, Patient $patient, PatientInsurerAssociation $association)
    {
        return $this->response(['check' => new PatientInsuranceCheckResource($this->workflow->recordCheck($patient, $association, $request->all(), $request->user()))], 201);
    }

    public function checkDetails(Request $request, Patient $patient, PatientInsurerAssociation $association, int $check)
    {
        return $this->response(['details' => $this->workflow->revealCheck($patient, $association, $check, $request->user())]);
    }

    private function response(mixed $data, int $status = 200)
    {
        return ApiResponse::success($data, statusCode: $status)->header('Cache-Control', 'private, no-store');
    }
}
