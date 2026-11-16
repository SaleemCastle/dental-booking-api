<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InsuranceBenefitRuleResource;
use App\Models\InsurancePlanVersion;
use App\Models\Patient;
use App\Models\PatientInsurerAssociation;
use App\Services\InsuranceBenefitWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class InsuranceBenefitController extends Controller
{
    public function __construct(private readonly InsuranceBenefitWorkflow $workflow) {}

    public function planIndex(Request $request, InsurancePlanVersion $version)
    {
        return $this->response($this->workflow->listing($version, $request->query(), $request->user()));
    }

    public function planStore(Request $request, InsurancePlanVersion $version)
    {
        return $this->response(['benefit' => new InsuranceBenefitRuleResource($this->workflow->record($version, $request->all(), $request->user()))], 201);
    }

    public function planDetails(Request $request, InsurancePlanVersion $version, int $benefit)
    {
        return $this->response(['details' => $this->workflow->details($version, $benefit, $request->user())]);
    }

    public function patientIndex(Request $request, Patient $patient, PatientInsurerAssociation $association)
    {
        return $this->response($this->workflow->listing($association, $request->query(), $request->user(), $patient));
    }

    public function patientStore(Request $request, Patient $patient, PatientInsurerAssociation $association)
    {
        return $this->response(['benefit' => new InsuranceBenefitRuleResource($this->workflow->record($association, $request->all(), $request->user(), $patient))], 201);
    }

    public function patientDetails(Request $request, Patient $patient, PatientInsurerAssociation $association, int $benefit)
    {
        return $this->response(['details' => $this->workflow->details($association, $benefit, $request->user(), $patient)]);
    }

    private function response(mixed $data, int $status = 200)
    {
        return ApiResponse::success($data, statusCode: $status)->header('Cache-Control', 'private, no-store');
    }
}
