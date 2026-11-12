<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunicationConsent;
use App\Models\OperationalNoticeBasis;
use App\Models\Patient;
use App\Services\AuditLogService;
use App\Services\CommunicationConsentService;
use App\Services\MessagingService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CommunicationConsentController extends Controller
{
    public function index(Request $request, Patient $patient, CommunicationConsentService $service)
    {
        $service->authorize($request->user());
        $data = $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1',
            'channel' => ['sometimes', Rule::in(['in_app', 'email', 'sms'])],
            'purpose' => ['sometimes', Rule::in(array_keys(CommunicationConsentService::SCOPES))]]);
        $query = CommunicationConsent::where('patient_id', $patient->id);
        foreach (['channel', 'purpose'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        return ApiResponse::success($query->orderByDesc('id')->paginate($data['per_page'] ?? 25), 'Consent history retrieved.')
            ->header('Cache-Control', 'private, no-store');
    }

    public function current(Request $request, Patient $patient, CommunicationConsentService $service)
    {
        $service->authorize($request->user());
        $data = $service->validateScope($request->all());

        return ApiResponse::success(['consent' => $service->resolve($patient->id, $data['purpose'], $data['scope'], $data['channel'])], 'Effective consent resolved.')
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Patient $patient, CommunicationConsentService $service)
    {
        return ApiResponse::success(['consent' => $service->record($patient->id, $request->all(), $request->user())], 'Consent evidence recorded.', 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function notices(Request $request, Patient $patient, CommunicationConsentService $service)
    {
        $service->authorize($request->user());
        $data = $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);

        return ApiResponse::success(OperationalNoticeBasis::where('patient_id', $patient->id)->orderByDesc('id')->paginate($data['per_page'] ?? 25), 'Operational notice basis history retrieved.')
            ->header('Cache-Control', 'private, no-store');
    }

    public function storeNotice(Request $request, Patient $patient, CommunicationConsentService $service)
    {
        $service->authorize($request->user());
        $data = $request->validate([
            'channel' => ['required', Rule::in(['in_app', 'email', 'sms'])],
            'basis' => ['required', Rule::in(['emergency', 'legal_obligation'])],
            'reason' => 'required|string|max:2000', 'authority_reference' => 'required|string|max:2000',
            'captured_by' => 'prohibited', 'captured_at' => 'prohibited', 'patient_id' => 'prohibited',
        ]);
        $basis = DB::transaction(function () use ($request, $patient, $data) {
            app(MessagingService::class)->lockTenant();
            $basis = OperationalNoticeBasis::create([...$data, 'patient_id' => $patient->id,
                'captured_by' => $request->user()->id, 'captured_at' => now('UTC')]);
            app(AuditLogService::class)->record('basis_recorded', 'operational_notice_bases', $basis->id, $request->user()->id);

            return $basis;
        });

        return ApiResponse::success(['notice_basis' => $basis, 'dispatch_authorized' => false], 'Operational basis recorded separately; this does not authorize a consent bypass.', 201);
    }
}
