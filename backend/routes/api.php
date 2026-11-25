<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerPortalController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentTypeController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RequestController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\WorkerController;
use Illuminate\Support\Facades\Route;

Route::get('config', [AuthController::class, 'config']);
Route::post('auth/login', [AuthController::class, 'login']);

Route::middleware(['token.query', 'auth:sanctum'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::get('workers/{worker}/photo', [WorkerController::class, 'photo']);
    Route::get('documents/{document}/scan', [DocumentController::class, 'scan']);

    Route::middleware('contractor')->group(function () {
        Route::get('dashboard', DashboardController::class);

        Route::get('workers', [WorkerController::class, 'index']);
        Route::post('workers', [WorkerController::class, 'store']);
        Route::get('workers/{worker}', [WorkerController::class, 'show']);
        Route::put('workers/{worker}', [WorkerController::class, 'update']);
        Route::delete('workers/{worker}', [WorkerController::class, 'destroy']);
        Route::post('workers/{worker}/photo', [WorkerController::class, 'uploadPhoto']);
        Route::post('workers/{worker}/documents', [DocumentController::class, 'store']);
        Route::post('documents/{document}', [DocumentController::class, 'update']);
        Route::delete('documents/{document}', [DocumentController::class, 'destroy']);

        Route::get('document-types', [DocumentTypeController::class, 'index']);
        Route::post('document-types', [DocumentTypeController::class, 'store']);
        Route::put('document-types/{documentType}', [DocumentTypeController::class, 'update']);
        Route::delete('document-types/{documentType}', [DocumentTypeController::class, 'destroy']);

        Route::get('customers', [CustomerController::class, 'index']);
        Route::post('customers', [CustomerController::class, 'store']);
        Route::put('customers/{customer}', [CustomerController::class, 'update']);
        Route::post('facilities', [CustomerController::class, 'storeFacility']);
        Route::put('facilities/{facility}', [CustomerController::class, 'updateFacility']);
        Route::delete('facilities/{facility}', [CustomerController::class, 'destroyFacility']);

        Route::get('profiles', [ProfileController::class, 'index']);
        Route::post('profiles', [ProfileController::class, 'store']);
        Route::put('profiles/{profile}', [ProfileController::class, 'update']);
        Route::delete('profiles/{profile}', [ProfileController::class, 'destroy']);
        Route::get('profiles/{profile}/readiness', [ProfileController::class, 'readiness']);

        Route::get('requests', [RequestController::class, 'index']);
        Route::post('requests', [RequestController::class, 'store']);
        Route::get('requests/{admissionRequest}', [RequestController::class, 'show']);
        Route::put('requests/{admissionRequest}', [RequestController::class, 'update']);
        Route::delete('requests/{admissionRequest}', [RequestController::class, 'destroy']);
        Route::post('requests/{admissionRequest}/workers', [RequestController::class, 'setWorkers']);
        Route::get('requests/{admissionRequest}/sign-data', [RequestController::class, 'signData']);
        Route::post('requests/{admissionRequest}/sign', [RequestController::class, 'sign']);
        Route::post('requests/{admissionRequest}/submit', [RequestController::class, 'submit']);
        Route::get('requests/{admissionRequest}/package', [RequestController::class, 'package']);
        Route::get('requests/{admissionRequest}/summary.pdf', [RequestController::class, 'summaryPdf']);
        Route::get('requests/{admissionRequest}/pass-list', [RequestController::class, 'passList']);
        Route::post('sign/emulate', [RequestController::class, 'emulateSign']);

        Route::get('exports/registry', [ExportController::class, 'registry']);
        Route::get('import/template', [ExportController::class, 'template']);
        Route::post('import', [ExportController::class, 'import']);

        Route::get('notifications', [SettingsController::class, 'notifications']);
        Route::post('notifications/run', [SettingsController::class, 'runNotifications']);
        Route::get('settings', [SettingsController::class, 'show']);
        Route::put('settings', [SettingsController::class, 'update']);
        Route::post('settings/users', [SettingsController::class, 'storeUser']);
        Route::post('settings/telegram-test', [SettingsController::class, 'testTelegram']);
    });

    Route::middleware('customer')->prefix('customer')->group(function () {
        Route::get('requests', [CustomerPortalController::class, 'index']);
        Route::get('requests/{admissionRequest}', [CustomerPortalController::class, 'show']);
        Route::post('requests/{admissionRequest}/decision', [CustomerPortalController::class, 'decide']);
        Route::get('requests/{admissionRequest}/package', [CustomerPortalController::class, 'package']);
    });
});
