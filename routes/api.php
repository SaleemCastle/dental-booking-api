<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\ClinicalNoteController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DentistController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\TreatmentController;
use App\Http\Controllers\Api\UserController;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return ApiResponse::success(
        data: $request->user()->loadMissing('roles.permissions'),
        message: 'Authenticated user retrieved.',
        request: $request,
    );
});

Route::get('health', function (Request $request) {
    return ApiResponse::success([
        'status' => 'ok',
        'service' => config('app.name'),
        'timestamp' => now()->toISOString(),
    ], 'Service is healthy.', request: $request);
});

Route::post('auth/token', [TokenController::class, 'store']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::delete('auth/token', [TokenController::class, 'destroy']);

    Route::get('dashboard/summary', [DashboardController::class, 'summary']);

    Route::post('appointments/{appointment}/check-in', [AppointmentController::class, 'checkIn']);
    Route::post('appointments/{appointment}/complete', [AppointmentController::class, 'complete']);
    Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);

    Route::apiResource('patients', PatientController::class);
    Route::apiResource('dentists', DentistController::class);
    Route::apiResource('appointments', AppointmentController::class);
    Route::apiResource('messages', MessageController::class);
    Route::apiResource('referrals', ReferralController::class);
    Route::apiResource('users', UserController::class);
    Route::apiResource('treatments', TreatmentController::class);
    Route::apiResource('invoices', InvoiceController::class);
    Route::apiResource('payments', PaymentController::class);
    Route::apiResource('clinical-notes', ClinicalNoteController::class);

    Route::put('patients/{patient}/update', [PatientController::class, 'update']);
    Route::delete('patients/{patient}/delete', [PatientController::class, 'destroy']);
});
