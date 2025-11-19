<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IntegrationApiKeyController;
use App\Http\Controllers\Api\IntegrationOrderController;
use App\Http\Controllers\Api\IntegrationProductController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\ReturnItemController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProductForecastController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TokenController;
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
    Route::get('/products/import/template', [ProductController::class, 'downloadTemplate']);
    Route::post('/products/import', [ProductController::class, 'import']);

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

    //----- Custom Integration Keys ------
    Route::get('/integration-keys', [IntegrationApiKeyController::class, 'index']);
    Route::post('/integration-keys', [IntegrationApiKeyController::class, 'store']);
    Route::put('/integration-keys/{integrationApiKey}', [IntegrationApiKeyController::class, 'update']);
    Route::delete('/integration-keys/{integrationApiKey}', [IntegrationApiKeyController::class, 'destroy']);

});

// ------ Custom API Produuct and Order sync ------
Route::prefix('integrations')->middleware('integration.auth')->group(function () {
    // Products
    Route::get('/products', [IntegrationProductController::class, 'index'])->middleware('integration.auth:products:read');
    Route::post('/products/sync', [IntegrationProductController::class, 'sync'])->middleware('integration.auth:products:write');

    // Orders
    Route::post('/orders/sync', [IntegrationOrderController::class, 'sync'])->middleware('integration.auth:orders:write');
});