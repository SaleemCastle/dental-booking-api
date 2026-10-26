<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DentalChartRevision;
use App\Models\Dentist;
use App\Support\ApiResponse;
use App\Tenancy\TenantRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DentistController extends Controller
{
    public function index()
    {
        return ApiResponse::success([
            'dentists' => Dentist::all(),
        ], 'Dentists retrieved.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', TenantRules::unique('dentist', 'email')],
            'contact_number' => ['required', 'string', 'max:255'],
            'specialization' => ['nullable', 'string', 'max:255'],
        ]);

        $dentist = Dentist::create($validated);

        return ApiResponse::success([
            'dentist' => $dentist,
        ], 'Dentist successfully created.', 201);
    }

    public function show(Dentist $dentist)
    {
        return ApiResponse::success([
            'dentist' => $dentist,
        ], 'Dentist retrieved.');
    }

    public function update(Request $request, Dentist $dentist)
    {
        $validated = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'required', 'email', 'max:255', TenantRules::unique('dentist', 'email')->ignore($dentist->id)],
            'contact_number' => ['sometimes', 'required', 'string', 'max:255'],
            'specialization' => ['nullable', 'string', 'max:255'],
        ]);

        $dentist->update($validated);

        return ApiResponse::success([
            'dentist' => $dentist->refresh(),
        ], 'Dentist successfully updated.');
    }

    public function destroy(Dentist $dentist)
    {
        DB::transaction(function () use ($dentist) {
            $current = Dentist::query()->lockForUpdate()->findOrFail($dentist->id);

            if (\App\Models\TreatmentPlanItem::where('dentist_id', $current->id)->exists()) {
                throw ValidationException::withMessages(['dentist_id' => ['A provider with treatment plan history cannot be deleted.']]);
            }

            if (DentalChartRevision::where('dentist_id', $current->id)->exists()) {
                throw ValidationException::withMessages(['dentist_id' => ['A provider with dental chart history cannot be deleted.']]);
            }

            if ($current->clinicalNotes()->withTrashed()->exists()) {
                throw ValidationException::withMessages([
                    'dentist_id' => ['A provider with clinical notes cannot be deleted.'],
                ]);
            }

            $current->delete();
        }, 3);

        return ApiResponse::success(message: 'Dentist successfully deleted.');
    }
}
