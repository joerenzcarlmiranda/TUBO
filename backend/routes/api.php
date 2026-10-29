<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoiceReadController;
use App\Http\Controllers\Api\InvoiceRetryController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
})->name('health');

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login');

    Route::middleware('auth:api')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('/refresh', [AuthController::class, 'refresh'])->name('refresh');
    });
});

Route::middleware('auth:api')->group(function (): void {
    Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
});

Route::prefix('v1')->name('v1.')->middleware('auth:api')->group(function (): void {
    Route::get('/invoices', [InvoiceReadController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [InvoiceReadController::class, 'show'])->name('invoices.show');
    Route::post('/invoices/{invoice}/retry', InvoiceRetryController::class)->name('invoices.retry');
});
