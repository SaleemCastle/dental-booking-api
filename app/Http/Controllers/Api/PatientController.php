<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PatientController extends Controller
{
    public function index() {
        $patients = Patient::all();
        if ($patients->count() > 0) {
            $data = [
                'status' => 200,
                'patients' => $patients
            ];
            return response()->json($data, 200);
        } else {
            return response()->json('No patients found', 404);
        }
    }

    public function store(Request $request) {
        $validator = Validator::make($request->all(), [
            'firstName' => 'required | max:100',
            'lastName' => 'required | max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => '402',
                'error' => $validator->messages()
            ], 422);
        } else {
            $patients = Patient::create([
                'firstName' => $request->firstName,
                'lastName' => $request->lastName,
                'sex' => $request->sex,
                'appointments' => $request->appointments,
                'streetAddress' => $request->streetAddress,
                'town' => $request->town,
                'city' => $request->city,
                'notes' => $request->notes,
            ]);

            if ($patients) {
                return response()->json([
                    'status' => 201,
                    'message' => 'Patient succesfully created!'
                ], 201);
            } else {
                return response()->json([
                    'status' => 500,
                    'message' => 'Internal Server Error, patient not created'
                ], 500);
            }
        }
    }

    public function show($id) {
        $patient = Patient::find($id);
        
        if ($patient) {
            return response()->json([
                'status' => '200',
                'patient' => $patient
            ], 404);          
        } else {
            return response()->json([
                'status' => '404',
                'message' => 'Patient not found'
            ], 404);
        }
    }

    public function update(Request $request, int $id) {
        $patient = Patient::find($id);
        if ($patient) {
            $patient->update([
                'firstName' => $request->firstName,
                'lastName' => $request->lastName,
                'sex' => $request->sex,
                'appointments' => $request->appointments,
                'streetAddress' => $request->streetAddress,
                'town' => $request->town,
                'city' => $request->city,
                'notes' => $request->notes,
            ]);
            
            return response()->json([
                'status' => '200',
                'patient' => $patient
            ], 404);          
        } else {
            return response()->json([
                'status' => '404',
                'message' => 'Patient not found'
            ], 404);
        }
        
    }

    public function destroy($id) {
        $patient = Patient::find($id);
        if ($patient) {
            $patient->delete();
            return response()->json([
                'status' => '200',
                'message' => 'Patient succesfully deleted.'
            ], 200);
        } else {
            return response()->json([
                'status' => '404',
                'message' => 'Patient not found'
            ], 404);
        }
    }
}
