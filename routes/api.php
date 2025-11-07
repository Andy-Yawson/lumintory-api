<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\ReturnItemController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Middleware\CheckTenantSubscription;
use Illuminate\Support\Facades\Route;

Route::post('/v1/register', [AuthController::class, 'register'])->withoutMiddleware([CheckTenantSubscription::class]);
Route::post('/v1/login', [AuthController::class, 'login'])->withoutMiddleware([CheckTenantSubscription::class]);
Route::post('/v1/register-tenant', [AuthController::class, 'registerTenant'])->withoutMiddleware([CheckTenantSubscription::class]);
Route::post('/v1/activate-subscription', [AuthController::class, 'activateSubscription'])->withoutMiddleware([CheckTenantSubscription::class]);

Route::middleware(['auth:sanctum', 'subscription'])->prefix('v1')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/add-user', [AuthController::class, 'addUser']);
    Route::get('/users', [AuthController::class, 'listUsers']);

    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::apiResource('products', ProductController::class);
    Route::apiResource('sales', SaleController::class);
    Route::apiResource('returns', ReturnItemController::class);
    Route::apiResource('customers', CustomerController::class);

    Route::get('/reports/sales', [ReportController::class, 'sales']);
    Route::get('/reports/stock', [ReportController::class, 'stock']);
    Route::get('/reports/top-products', [ReportController::class, 'topProducts']);
    Route::get('/reports/returns', [ReportController::class, 'returns']);

    Route::post('/subscription', [SubscriptionController::class, 'update']);
    Route::get('/sales/{sale}/receipt', [SaleController::class, 'receipt']);
});
