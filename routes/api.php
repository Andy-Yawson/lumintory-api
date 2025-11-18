<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\ReturnItemController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProductForecastController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StoreConnectionController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Middleware\CheckTenantSubscription;
use Illuminate\Support\Facades\Route;


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

    Route::post('/rewards/daily-login', [TokenController::class, 'dailyLogin']);
    Route::post('/rewards/redeem-sms', [TokenController::class, 'redeemSMS']);
    Route::get('/rewards/summary', [TokenController::class, 'summary']);
    Route::get('/rewards/referrals', [TokenController::class, 'referrals']);

    //----- Forecast Model ------
    Route::get('/product-forecasts', [ProductForecastController::class, 'index']);
    Route::get('/inventory-insights', [ProductForecastController::class, 'dashboardInsights']);

    Route::get('stores', [StoreConnectionController::class, 'index']);
    Route::post('stores', [StoreConnectionController::class, 'store']);
    Route::delete('stores/{id}', [StoreConnectionController::class, 'destroy']);
    Route::post('stores/{id}/test', [StoreConnectionController::class, 'testConnection']);
    Route::get('stores/{id}/logs', [StoreConnectionController::class, 'logs']);
    Route::post('/stores/{store}/sync-products', [StoreConnectionController::class, 'syncProducts']);

    // Public webhooks - no auth (provider calls this)
    Route::post('webhooks/{provider}/{connectionId}', [WebhookController::class, 'handle']);

});
