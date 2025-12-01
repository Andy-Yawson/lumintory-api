<?php

use App\Http\Controllers\Api\Admin\AdminAuditLogController;
use App\Http\Controllers\Api\Admin\AdminBackupController;
use App\Http\Controllers\Api\Admin\AdminSubscriptionController;
use App\Http\Controllers\Api\Admin\AdminSupportTicketController;
use App\Http\Controllers\Api\Admin\AdminTenantController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IntegrationApiKeyController;
use App\Http\Controllers\Api\IntegrationOrderController;
use App\Http\Controllers\Api\IntegrationProductController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\ReturnItemController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProductForecastController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SmsController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\TenantSettingsController;
use App\Http\Controllers\Api\TokenController;
use Illuminate\Support\Facades\Route;


Route::post('/v1/login', [AuthController::class, 'login']);
Route::post('/v1/register-tenant', [AuthController::class, 'registerTenant'])->middleware('throttle:3,1');

Route::post('/v1/password/forgot', [PasswordResetController::class, 'requestReset']);
Route::post('/v1/password/reset', [PasswordResetController::class, 'resetPassword']);


Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/add-user', [AuthController::class, 'addUser'])->middleware('limit.users');
    Route::get('/users', [AuthController::class, 'listUsers']);
    Route::post('/password/change', [AuthController::class, 'changePassword']);
    Route::post('/v1/activate-subscription', [AuthController::class, 'activateSubscription']);
    Route::get('/tenant/settings', [TenantSettingsController::class, 'show']);
    Route::post('/tenant/settings', [TenantSettingsController::class, 'update']);

    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::post('product', [ProductController::class, 'store'])->middleware('limit.products');
    Route::apiResource('products', ProductController::class);
    Route::get('/products/import/template', [ProductController::class, 'downloadTemplate']);
    Route::post('/products/import', [ProductController::class, 'import']);

    Route::post('sales', [SaleController::class, 'store'])->middleware('limit.sales');
    Route::apiResource('sales', SaleController::class);

    Route::post('returns', [ReturnItemController::class, 'store'])->middleware('limit.returns');
    Route::apiResource('returns', ReturnItemController::class);

    Route::post('customers', [CustomerController::class, 'store'])->middleware('limit.customers');
    Route::apiResource('customers', CustomerController::class);

    Route::get('/reports/sales', [ReportController::class, 'sales']);
    Route::get('/reports/stock', [ReportController::class, 'stock']);
    Route::get('/reports/top-products', [ReportController::class, 'topProducts']);
    Route::get('/reports/returns', [ReportController::class, 'returns']);
    Route::get('/reports/usage', [ReportController::class, 'index']);

    Route::post('/subscription', [SubscriptionController::class, 'update']);
    Route::get('/sales/{sale}/receipt', [SaleController::class, 'receipt']);

    Route::post('/rewards/daily-login', [TokenController::class, 'dailyLogin']);
    Route::post('/rewards/redeem-sms', [TokenController::class, 'redeemSMS']);
    Route::get('/rewards/summary', [TokenController::class, 'summary']);
    Route::get('/rewards/referrals', [TokenController::class, 'referrals']);

    Route::post('/sms/send', [SmsController::class, 'send'])->middleware('limit.sms');
    Route::post('/sms/send-bulk', [SmsController::class, 'sendBulk'])->middleware('limit.sms');
    Route::get('/sms/credits', [SmsController::class, 'getSMSCredit']);

    //----- Forecast Model ------
    Route::get('/product-forecasts', [ProductForecastController::class, 'index']);
    Route::get('/inventory-insights', [ProductForecastController::class, 'dashboardInsights']);

    Route::get('/audit/logs', [AuditLogController::class, 'index']);
    Route::get('/audit/stats', [AuditLogController::class, 'stats']);

    // ------- Suppot Ticket -------
    Route::get('/support-tickets/analytics', [SupportTicketController::class, 'analytics']);
    Route::get('/support-tickets', [SupportTicketController::class, 'index']);
    Route::get('/support-tickets/{ticket}', [SupportTicketController::class, 'show']);
    Route::post('/support-tickets', [SupportTicketController::class, 'store'])->middleware('limit.ticket');
    Route::post('/support-tickets/{ticket}/messages', [SupportTicketController::class, 'addMessage']);
    Route::patch('/support-tickets/{ticket}/status', [SupportTicketController::class, 'updateStatus']);


    //----- Custom Integration Keys ------
    Route::get('/integration-keys', [IntegrationApiKeyController::class, 'index']);
    Route::post('/integration-keys', [IntegrationApiKeyController::class, 'store']);
    Route::put('/integration-keys/{integrationApiKey}', [IntegrationApiKeyController::class, 'update']);
    Route::delete('/integration-keys/{integrationApiKey}', [IntegrationApiKeyController::class, 'destroy']);

});

// ------ Custom API Produuct and Order sync ------
Route::prefix('v1/integrations')->middleware('integration.auth')->group(function () {
    // Products
    Route::get('/products', [IntegrationProductController::class, 'index'])->middleware('integration.auth:products:read');
    Route::post('/products/sync', [IntegrationProductController::class, 'sync'])->middleware('integration.auth:products:write');

    // Orders
    Route::post('/orders/sync', [IntegrationOrderController::class, 'sync'])->middleware('integration.auth:orders:write');
});


Route::middleware(['auth:sanctum', 'superadmin'])->prefix('v1/admin')->group(function () {
    // Tenants
    Route::get('/tenants', [AdminTenantController::class, 'index']);
    Route::get('/tenants/{tenant}', [AdminTenantController::class, 'show']);
    Route::patch('/tenants/{tenant}', [AdminTenantController::class, 'update']);
    Route::get('/tenants/{tenant}/usage', [AdminTenantController::class, 'usage']);

    // Subscriptions (v1: summary + expiring)
    Route::get('/subscriptions/summary', [AdminSubscriptionController::class, 'summary']);
    Route::get('/subscriptions/tenants', [AdminSubscriptionController::class, 'tenants']);
    Route::get('/subscriptions/history', [AdminSubscriptionController::class, 'history']);
    Route::get('/subscriptions/history/tenant/{tenant}', [AdminSubscriptionController::class, 'historyByTenant']);
    Route::get('/subscriptions/history/charts', [AdminSubscriptionController::class, 'charts']);

    // Audit logs
    Route::get('/audit-logs', [AdminAuditLogController::class, 'index']);
    Route::get('/audit-logs/stats', [AdminAuditLogController::class, 'stats']);

    // Support tickets
    Route::get('/support/tickets', [AdminSupportTicketController::class, 'index']);
    Route::get('/support/tickets/{ticket}', [AdminSupportTicketController::class, 'show']);
    Route::post('/support/tickets/{ticket}/reply', [AdminSupportTicketController::class, 'reply']);
    Route::post('/support/tickets/{ticket}/note', [AdminSupportTicketController::class, 'addInternalNote']);
    Route::post('/support/tickets/{ticket}/status', [AdminSupportTicketController::class, 'updateStatus']);
    Route::post('/support/tickets/{ticket}/assign', [AdminSupportTicketController::class, 'assign']);
    // analytics endpoints for charts
    Route::get('/support/analytics/overview', [AdminSupportTicketController::class, 'analyticsOverview']);
    Route::get('/support/analytics/tickets-per-day', [AdminSupportTicketController::class, 'ticketsPerDay']);
    Route::get('/support/analytics/by-category', [AdminSupportTicketController::class, 'ticketsByCategory']);
    Route::get('/support/analytics/high-friction-tenants', [AdminSupportTicketController::class, 'highFrictionTenants']);


    // Backups
    Route::get('/backups', [AdminBackupController::class, 'index']);
    Route::post('/backups', [AdminBackupController::class, 'store']);
    Route::get('/backups/{backup}/download', [AdminBackupController::class, 'download']);

    Route::post('/add-user', [AuthController::class, 'addUserAdmin']);

});