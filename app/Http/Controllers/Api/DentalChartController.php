<?php

namespace App\Http\Controllers\Api;

use App\Dental\DentalChartSchema;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDentalChartRevisionRequest;
use App\Http\Resources\DentalChartRevisionResource;
use App\Models\DentalChartRevision;
use App\Models\Patient;
use App\Services\DentalChartWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DentalChartController extends Controller
{
    public function metadata(Request $request)
    {
        $this->authorize('viewAny', DentalChartRevision::class);
        $data = $request->validate(['numbering_system' => ['sometimes', 'required', Rule::in(DentalChartSchema::NUMBERING_SYSTEMS)]]);

        return ApiResponse::success(DentalChartSchema::metadata($data['numbering_system'] ?? DentalChartSchema::DEFAULT_NUMBERING), 'Dental chart metadata retrieved.')
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Patient $patient)
    {
        $this->authorize('viewAny', DentalChartRevision::class);
        $selection = $this->selection($request);
        $revision = $this->query($patient, $selection)->orderByDesc('version')->first();
        $chart = $revision === null ? [
            'id' => null, 'patient_id' => $patient->id, 'dentition' => $selection['dentition'],
            'numbering_system' => $selection['numbering_system'], 'version' => 0, 'teeth' => [],
            'dentist_id' => null, 'author_user_id' => null, 'clinical_note_id' => null, 'recorded_at' => null, 'reason' => null,
        ] : new DentalChartRevisionResource($revision, $selection['numbering_system']);

        return ApiResponse::success(['chart' => $chart], 'Dental chart retrieved.')->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreDentalChartRevisionRequest $request, Patient $patient, DentalChartWorkflow $workflow)
    {
        $revision = $workflow->append($patient, $request->validated(), $request->user());

        return ApiResponse::success(['chart' => new DentalChartRevisionResource($revision, $request->validated('numbering_system', DentalChartSchema::DEFAULT_NUMBERING))], 'Dental chart revision created.', 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function history(Request $request, Patient $patient)
    {
        $this->authorize('viewAny', DentalChartRevision::class);
        $selection = $this->selection($request);
        $pagination = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        $revisions = $this->query($patient, $selection)->orderByDesc('version')->paginate($pagination['per_page'] ?? 10);

        return ApiResponse::success(['revisions' => $revisions->getCollection()->map(fn ($revision) => new DentalChartRevisionResource($revision, $selection['numbering_system'], false))],
            'Dental chart history retrieved.', meta: ['pagination' => [
                'currentPage' => $revisions->currentPage(), 'perPage' => $revisions->perPage(), 'total' => $revisions->total(),
                'lastPage' => $revisions->lastPage(), 'from' => $revisions->firstItem(), 'to' => $revisions->lastItem(), 'hasMorePages' => $revisions->hasMorePages(),
            ]])->header('Cache-Control', 'private, no-store');
    }

    public function revision(Request $request, Patient $patient, string $version)
    {
        $this->authorize('viewAny', DentalChartRevision::class);
        $selection = $this->selection($request);
        $revision = $this->query($patient, $selection)->where('version', $version)->firstOrFail();

        return ApiResponse::success(['chart' => new DentalChartRevisionResource($revision, $selection['numbering_system'])], 'Dental chart revision retrieved.')
            ->header('Cache-Control', 'private, no-store');
    }

    private function selection(Request $request): array
    {
        return array_merge(['dentition' => 'adult', 'numbering_system' => DentalChartSchema::DEFAULT_NUMBERING], $request->validate([
            'dentition' => ['sometimes', 'required', Rule::in(DentalChartSchema::DENTITIONS)],
            'numbering_system' => ['sometimes', 'required', Rule::in(DentalChartSchema::NUMBERING_SYSTEMS)],
        ]));
    }

    private function query(Patient $patient, array $selection)
    {
        return DentalChartRevision::where('patient_id', $patient->id)->where('dentition', $selection['dentition']);
    }
}
