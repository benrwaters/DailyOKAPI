<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CarerController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\CarerSubscriptionController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\AppleWebhookController;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/ping', function () {
    return response()->json(['ok' => true]);
});

Route::middleware('auth:sanctum')->post('carer/subscription/sync', [CarerSubscriptionController::class, 'sync']);
Route::post('webhooks/apple/app-store-notifications', [AppleWebhookController::class, 'handle']);

Route::prefix('v1')->group(function () {
    Route::post('auth/carer/request-otp', [AuthController::class, 'carer_request_otp']);
    Route::post('auth/carer/verify-otp', [AuthController::class, 'carer_verify_otp']);

    Route::post('auth/patient/login', [AuthController::class, 'patient_login']);
    Route::post('auth/patient/request-otp', [AuthController::class, 'patient_request_otp']);
    Route::post('auth/patient/verify-otp', [AuthController::class, 'patient_verify_otp']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('devices/register', [DeviceController::class, 'register']);
        Route::post('carer/subscription/sync', [CarerSubscriptionController::class, 'sync']);
        Route::get('carer/profile', [CarerController::class, 'profile']);
        Route::patch('carer/profile', [CarerController::class, 'update_profile']);

        Route::get('carer/loved-ones', [CarerController::class, 'list_loved_one_invites']);
        Route::post('carer/loved-ones', [CarerController::class, 'create_loved_one_and_invite']);
        Route::get('carer/loved-ones/{id}/check-ins', [CarerController::class, 'loved_one_check_ins']);
        Route::post('carer/loved-ones/{id}/request-check-in-now', [CarerController::class, 'request_loved_one_check_in_now']);
        Route::delete('carer/loved-ones/{id}', [CarerController::class, 'delete_loved_one']);
        Route::post('carer/loved-ones/{invite_id}/resend', [CarerController::class, 'resend_loved_one_invite']);
        Route::post('carer/loved-ones/{invite_id}/cancel', [CarerController::class, 'cancel_loved_one_invite']);

        Route::get('patient/home', [PatientController::class, 'home']);
        Route::post('patient/check-ins', [PatientController::class, 'check_in_now']);
    });
});
