<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientContact;
use App\Services\PatientContactService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class PatientContactController extends Controller
{
    public function index(Patient $patient)
    {
        $this->authorize('view', $patient);

        return ApiResponse::success(['contacts' => PatientContact::where('patient_id', $patient->id)->orderBy('id')->get()
            ->map(fn ($contact) => [...$contact->toArray(), 'address' => $contact->address])]);
    }

    public function store(Request $request, Patient $patient, PatientContactService $service)
    {
        $contact = $service->create($patient, $request->all(), $request->user());

        return ApiResponse::success(['contact' => [...$contact->toArray(), 'address' => $contact->address]], 'Contact saved; verification is required.', 201);
    }

    public function verify(Request $request, Patient $patient, int $contact, PatientContactService $service)
    {
        $this->authorize('update', $patient);
        $data = $request->validate(['verification_method' => 'required|string']);
        $result = $service->verify($patient, $contact, $data['verification_method'], $request->user());

        return ApiResponse::success(['contact' => $result], 'Contact verification recorded.');
    }

    public function destroy(Request $request, Patient $patient, int $contact, PatientContactService $service)
    {
        return ApiResponse::success(['contact' => $service->revoke($patient, $contact, $request->user())], 'Contact revoked.');
    }
}
