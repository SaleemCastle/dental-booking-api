<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InsuranceClaimAppealResource;
use App\Http\Resources\InsuranceClaimAppealResponseResource;
use App\Http\Resources\InsuranceClaimEventResource;
use App\Http\Resources\InsuranceClaimLineResource;
use App\Http\Resources\InsuranceClaimResource;
use App\Http\Resources\InsuranceClaimResponseResource;
use App\Http\Resources\InsuranceClaimSubmissionResource;
use App\Models\InsuranceClaim;
use App\Models\InsuranceClaimAppeal;
use App\Models\InsuranceClaimLine;
use App\Models\Patient;
use App\Services\InsuranceClaimResponseWorkflow;
use App\Services\InsuranceClaimWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class InsuranceClaimController extends Controller
{
    public function __construct(private readonly InsuranceClaimWorkflow $workflow, private readonly InsuranceClaimResponseWorkflow $responses) {}

    public function index(Request $request, Patient $patient)
    {
        $page = $this->workflow->listing($patient, $request->user(), $request->query());
        $page->setCollection($page->getCollection()->map(fn ($claim) => new InsuranceClaimResource($claim)));

        return $this->response($page);
    }

    public function store(Request $request, Patient $patient)
    {
        return $this->response(['claim' => new InsuranceClaimResource($this->workflow->create($patient, $request->all(), $request->user()))], 201);
    }

    public function createCorrection(Request $request, Patient $patient, InsuranceClaim $claim)
    {
        return $this->response(['claim' => new InsuranceClaimResource($this->workflow->createCorrection($patient, $claim, $request->all(), $request->user()))], 201);
    }

    public function show(Request $request, Patient $patient, InsuranceClaim $claim)
    {
        return $this->response(['claim' => new InsuranceClaimResource($this->workflow->show($patient, $claim, $request->user()))]);
    }

    public function update(Request $request, Patient $patient, InsuranceClaim $claim)
    {
        return $this->response(['claim' => new InsuranceClaimResource($this->workflow->update($patient, $claim, $request->all(), $request->user()))]);
    }

    public function addLine(Request $request, Patient $patient, InsuranceClaim $claim)
    {
        $result = $this->workflow->addLine($patient, $claim, $request->all(), $request->user());

        return $this->response(['claim' => new InsuranceClaimResource($result['claim']), 'line' => new InsuranceClaimLineResource($result['line'])], 201);
    }

    public function updateLine(Request $request, Patient $patient, InsuranceClaim $claim, InsuranceClaimLine $line)
    {
        $result = $this->workflow->updateLine($patient, $claim, $line, $request->all(), $request->user());

        return $this->response(['claim' => new InsuranceClaimResource($result['claim']), 'line' => new InsuranceClaimLineResource($result['line'])]);
    }

    public function removeLine(Request $request, Patient $patient, InsuranceClaim $claim, InsuranceClaimLine $line)
    {
        $updatedClaim = $this->workflow->removeLine($patient, $claim, $line, $request->all(), $request->user());

        return $this->response(['claim' => new InsuranceClaimResource($updatedClaim)]);
    }

    public function validateReadiness(Request $request, Patient $patient, InsuranceClaim $claim)
    {
        $result = $this->workflow->validateReadiness($patient, $claim, $request->all(), $request->user());

        return $this->response(['claim' => new InsuranceClaimResource($result['claim']), 'ready' => $result['ready'], 'reason_codes' => $result['reason_codes']]);
    }

    public function cancel(Request $request, Patient $patient, InsuranceClaim $claim)
    {
        return $this->response(['claim' => new InsuranceClaimResource($this->workflow->cancel($patient, $claim, $request->all(), $request->user()))]);
    }

    public function submit(Request $request, Patient $patient, InsuranceClaim $claim)
    {
        $result = $this->workflow->submit($patient, $claim, $request->all(), (string) $request->header('Idempotency-Key'), $request->user());

        return $this->response([
            'claim' => new InsuranceClaimResource($result['claim']),
            'submission' => new InsuranceClaimSubmissionResource($result['submission']),
            'replayed' => $result['replayed'],
        ], $result['replayed'] ? 200 : 201);
    }

    public function submissions(Request $request, Patient $patient, InsuranceClaim $claim)
    {
        $submissions = $this->workflow->submissions($patient, $claim, $request->user());

        return $this->response(['submissions' => InsuranceClaimSubmissionResource::collection($submissions)]);
    }

    public function events(Request $request, Patient $patient, InsuranceClaim $claim)
    {
        return $this->response(['events' => InsuranceClaimEventResource::collection($this->workflow->events($patient, $claim, $request->user()))]);
    }

    public function recordResponse(Request $request, Patient $patient, InsuranceClaim $claim)
    {
        return $this->response(['response' => new InsuranceClaimResponseResource($this->responses->record($patient, $claim, $request->all(), $request->user()))], 201);
    }

    public function responses(Request $request, Patient $patient, InsuranceClaim $claim)
    {
        $page = $this->responses->responses($patient, $claim, $request->user());
        $page->setCollection($page->getCollection()->map(fn ($item) => new InsuranceClaimResponseResource($item)));

        return $this->response($page);
    }

    public function createAppeal(Request $request, Patient $patient, InsuranceClaim $claim)
    {
        return $this->response(['appeal' => new InsuranceClaimAppealResource($this->responses->createAppeal($patient, $claim, $request->all(), $request->user()))], 201);
    }

    public function submitAppeal(Request $request, Patient $patient, InsuranceClaim $claim, InsuranceClaimAppeal $appeal)
    {
        return $this->response(['appeal' => new InsuranceClaimAppealResource($this->responses->submitAppeal($patient, $claim, $appeal, $request->all(), $request->user()))]);
    }

    public function recordAppealResponse(Request $request, Patient $patient, InsuranceClaim $claim, InsuranceClaimAppeal $appeal)
    {
        return $this->response(['response' => new InsuranceClaimAppealResponseResource($this->responses->recordAppealResponse($patient, $claim, $appeal, $request->all(), $request->user()))], 201);
    }

    public function withdrawAppeal(Request $request, Patient $patient, InsuranceClaim $claim, InsuranceClaimAppeal $appeal)
    {
        return $this->response(['appeal' => new InsuranceClaimAppealResource($this->responses->withdrawAppeal($patient, $claim, $appeal, $request->user()))]);
    }

    public function appealHistory(Request $request, Patient $patient, InsuranceClaim $claim)
    {
        $page = $this->responses->appealHistory($patient, $claim, $request->user());
        $page->setCollection($page->getCollection()->map(fn ($item) => new InsuranceClaimAppealResource($item)));

        return $this->response($page);
    }

    private function response(mixed $data, int $status = 200)
    {
        return ApiResponse::success($data, statusCode: $status)->header('Cache-Control', 'private, no-store');
    }
}
