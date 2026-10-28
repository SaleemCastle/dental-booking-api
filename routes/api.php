<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AppointmentLocationController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\ClinicalNoteController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DentalChartController;
use App\Http\Controllers\Api\DentistController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PatientDocumentController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentLinkController;
use App\Http\Controllers\Api\ProviderAvailabilityController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\TreatmentController;
use App\Http\Controllers\Api\TreatmentPlanController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\RequireAdminRole;
use App\Support\ApiResponse;
use App\Tenancy\TenantContext;
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

Route::get('health', function (Request $request) {
    return ApiResponse::success([
        'status' => 'ok',
        'service' => config('app.name'),
        'timestamp' => now()->toISOString(),
    ], 'Service is healthy.', request: $request);
});

Route::post('auth/token', [TokenController::class, 'store']);

Route::middleware('auth:sanctum')->get('tenants', function (Request $request) {
    return ApiResponse::success(['tenants' => $request->user()->tenants()->where('active', true)->orderBy('tenants.id')->get()], 'Clinic memberships retrieved.');
});

Route::middleware('auth:sanctum')->delete('auth/token', [TokenController::class, 'destroy']);

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('/user', function (Request $request, TenantContext $context) {
        return ApiResponse::success(
            data: array_merge($request->user()->loadMissing('roles.permissions')->toArray(), [
                'current_tenant_id' => $context->requireId(),
            ]),
            message: 'Authenticated user retrieved.',
            request: $request,
        );
    });

    Route::get('dashboard/summary', [DashboardController::class, 'summary']);

    Route::get('reports/appointments', [ReportController::class, 'appointments']);
    Route::get('reports/providers', [ReportController::class, 'providers']);
    Route::get('reports/treatments', [ReportController::class, 'treatments']);
    Route::get('reports/billing', [ReportController::class, 'billing']);

    Route::get('appointment-locations', [AppointmentLocationController::class, 'index']);
    Route::get('dentists/{dentist}/availability', [ProviderAvailabilityController::class, 'show']);
    Route::put('dentists/{dentist}/availability', [ProviderAvailabilityController::class, 'update']);

    Route::get('dental-chart/metadata', [DentalChartController::class, 'metadata']);
    Route::get('patients/{patient}/documents', [PatientDocumentController::class, 'index']);
    Route::post('patients/{patient}/documents', [PatientDocumentController::class, 'store']);
    Route::get('patients/{patient}/documents/{document}', [PatientDocumentController::class, 'show']);
    Route::get('patients/{patient}/documents/{document}/download', [PatientDocumentController::class, 'download']);
    Route::post('patients/{patient}/documents/{document}/archive', [PatientDocumentController::class, 'archive']);
    Route::delete('patients/{patient}/documents/{document}', [PatientDocumentController::class, 'destroy'])->withTrashed();
    Route::get('patients/{patient}/treatment-plans', [TreatmentPlanController::class, 'index']);
    Route::post('patients/{patient}/treatment-plans', [TreatmentPlanController::class, 'store']);
    Route::get('treatment-plans/{treatment_plan}', [TreatmentPlanController::class, 'show']);
    Route::match(['put', 'patch'], 'treatment-plans/{treatment_plan}', [TreatmentPlanController::class, 'update']);
    Route::post('treatment-plans/{treatment_plan}/items', [TreatmentPlanController::class, 'storeItem']);
    Route::match(['put', 'patch'], 'treatment-plans/{treatment_plan}/items/{item}', [TreatmentPlanController::class, 'updateItem'])->whereNumber('item');
    Route::get('patients/{patient}/dental-chart', [DentalChartController::class, 'show']);
    Route::get('patients/{patient}/dental-chart/history', [DentalChartController::class, 'history']);
    Route::get('patients/{patient}/dental-chart/revisions/{version}', [DentalChartController::class, 'revision'])->whereNumber('version');
    Route::post('patients/{patient}/dental-chart/revisions', [DentalChartController::class, 'store']);

    Route::get('audit-logs/filters', [AuditLogController::class, 'filters']);
    Route::get('audit-logs', [AuditLogController::class, 'index']);
    Route::get('audit-logs/{audit_log}', [AuditLogController::class, 'show'])->whereNumber('audit_log');

    Route::post('appointments/{appointment}/check-in', [AppointmentController::class, 'checkIn']);
    Route::post('appointments/{appointment}/complete', [AppointmentController::class, 'complete']);
    Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);

    Route::patch('tasks/{task}/status', [TaskController::class, 'updateStatus']);

    Route::apiResource('patients', PatientController::class);
    Route::apiResource('dentists', DentistController::class);
    Route::apiResource('appointments', AppointmentController::class);
    Route::apiResource('messages', MessageController::class);
    Route::apiResource('referrals', ReferralController::class);
    Route::apiResource('users', UserController::class);
    Route::apiResource('treatments', TreatmentController::class);
    Route::apiResource('invoices', InvoiceController::class)->except('destroy');
    Route::apiResource('payments', PaymentController::class)->only(['index', 'store', 'show']);
    Route::get('invoices/{invoice}/payment-links', [PaymentLinkController::class, 'index']);
    Route::post('invoices/{invoice}/payment-links', [PaymentLinkController::class, 'store']);
    Route::get('payment-links/{payment_link}', [PaymentLinkController::class, 'show']);
    Route::post('payment-links/{payment_link}/cancel', [PaymentLinkController::class, 'cancel']);
    Route::post('payment-links/{payment_link}/refresh', [PaymentLinkController::class, 'refresh']);
    Route::apiResource('clinical-notes', ClinicalNoteController::class);
    Route::apiResource('tasks', TaskController::class);

    Route::middleware(RequireAdminRole::class)->group(function () {
        Route::apiResource('roles', RoleController::class);
        Route::get('permissions', [RoleController::class, 'permissions']);
        Route::put('roles/{role}/permissions', [RoleController::class, 'updatePermissions']);
        Route::put('users/{user}/roles', [RoleController::class, 'updateUserRoles']);
    });

    Route::put('patients/{patient}/update', [PatientController::class, 'update']);
    Route::delete('patients/{patient}/delete', [PatientController::class, 'destroy']);
});
