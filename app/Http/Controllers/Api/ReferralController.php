<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function index()
    {
        return ApiResponse::success([
            'referrals' => Referral::with(['patient', 'dentist'])->get(),
        ], 'Referrals retrieved.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'integer', 'exists:patient,id'],
            'dentist_id' => ['required', 'integer', 'exists:dentist,id'],
            'referred_to_email' => ['required', 'email', 'max:255'],
            'referral_date' => ['required', 'date'],
            'referral_notes' => ['required', 'string'],
        ]);

        $referral = Referral::create($validated);

        return ApiResponse::success([
            'referral' => $referral->load(['patient', 'dentist']),
        ], 'Referral successfully created.', 201);
    }

    public function show(Referral $referral)
    {
        return ApiResponse::success([
            'referral' => $referral->load(['patient', 'dentist']),
        ], 'Referral retrieved.');
    }

    public function update(Request $request, Referral $referral)
    {
        $validated = $request->validate([
            'patient_id' => ['sometimes', 'required', 'integer', 'exists:patient,id'],
            'dentist_id' => ['sometimes', 'required', 'integer', 'exists:dentist,id'],
            'referred_to_email' => ['sometimes', 'required', 'email', 'max:255'],
            'referral_date' => ['sometimes', 'required', 'date'],
            'referral_notes' => ['sometimes', 'required', 'string'],
        ]);

        $referral->update($validated);

        return ApiResponse::success([
            'referral' => $referral->refresh()->load(['patient', 'dentist']),
        ], 'Referral successfully updated.');
    }

    public function destroy(Referral $referral)
    {
        $referral->delete();

        return ApiResponse::success(message: 'Referral successfully deleted.');
    }
}
