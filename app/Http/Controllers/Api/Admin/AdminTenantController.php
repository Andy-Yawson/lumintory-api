<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\MailHelper;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SmsCredit;
use App\Models\Tenant;
use App\Models\TenantToken;
use App\Models\User;
use App\Services\PlanLimit;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AdminTenantController extends Controller
{
    public function index(Request $request)
    {
        $query = Tenant::query()->withCount(['users']);

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('domain', 'like', "%{$search}%");
            });
        }

        if ($plan = $request->get('plan')) {
            $query->where('plan', $plan);
        }

        if (!is_null($request->get('is_active'))) {
            $isActive = filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (!is_null($isActive)) {
                $query->where('is_active', $isActive);
            }
        }

        // Created date range filter
        if ($from = $request->get('created_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->get('created_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        // Optional sort by last active
        if ($request->get('sort') === 'last_active') {
            $query->orderByDesc('last_active_at');
        } else {
            $query->orderByDesc('created_at');
        }

        $tenants = $query->paginate($request->get('per_page', 15));

        return response()->json($tenants);
    }


    public function show(Tenant $tenant)
    {
        $tenant->load('users:id,name,email,tenant_id');

        return response()->json($tenant);
    }

    public function update(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'plan' => 'sometimes|in:basic,pro,custom',
            'is_active' => 'sometimes|boolean',
            'subscription_ends_at' => 'sometimes|date',
        ]);

        $tenantAdmin = User::where('tenant_id', $tenant->id)->where('role', 'admin')->first();

        if (isset($validated['plan'])) {
            $tenant->plan = $validated['plan'];
            MailHelper::sendEmailNotification($tenantAdmin->email, 'Plan Updated', 'Your plan has been updated to ' . $validated['plan']);
        }

        if (isset($validated['is_active'])) {
            $tenant->is_active = $validated['is_active'];
            MailHelper::sendEmailNotification($tenantAdmin->email, 'Account Status Updated', 'Your account status has been updated to ' . ($validated['is_active'] ? 'Active' : 'Inactive'));
        }

        if (isset($validated['subscription_ends_at'])) {
            $tenant->subscription_ends_at = Carbon::parse($validated['subscription_ends_at']);
            MailHelper::sendEmailNotification($tenantAdmin->email, 'Subscription Ends At Updated', 'Your subscription ends at has been updated to ' . $validated['subscription_ends_at']);
        }

        $tenant->save();

        return response()->json([
            'message' => 'Tenant updated successfully.',
            'tenant' => $tenant->fresh(),
        ]);
    }

    public function usage(Tenant $tenant)
    {
        $productsCount = Product::where('tenant_id', $tenant->id)->count();
        $customersCount = Customer::where('tenant_id', $tenant->id)->count();

        $smsCredit = SmsCredit::where('tenant_id', $tenant->id)->first();

        $planLimits = config("plan_limits.{$tenant->plan}", []);

        $productLimit = $planLimits['products'] ?? null;
        $customerLimit = $planLimits['customers'] ?? null;
        $smsLimit = $planLimits['sms'] ?? null;

        $productUsagePct = $productLimit ? round(($productsCount / $productLimit) * 100, 1) : null;
        $customerUsagePct = $customerLimit ? round(($customersCount / $customerLimit) * 100, 1) : null;

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'plan' => $tenant->plan,
            ],
            'products' => [
                'used' => $productsCount,
                'limit' => $productLimit,
                'usage_pct' => $productUsagePct,
                'is_high' => $productUsagePct !== null && $productUsagePct >= 80,
            ],
            'customers' => [
                'used' => $customersCount,
                'limit' => $customerLimit,
                'usage_pct' => $customerUsagePct,
                'is_high' => $customerUsagePct !== null && $customerUsagePct >= 80,
            ],
            'sms' => [
                'balance' => $smsCredit?->credits ?? 0,
                'limit' => $smsLimit,
            ],
            'tokens' => [
                'balance' => optional(TenantToken::where('tenant_id', $tenant->id)->first())->balance ?? 0,
            ],
            'subscription_ends_at' => $tenant->subscription_ends_at,
            'created_at' => $tenant->created_at,
            'last_active_at' => $tenant->last_active_at,
        ]);
    }

}
