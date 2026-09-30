<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\SubscriptionHistory;
use App\Services\PlanLimit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminPlanController extends Controller
{
    /** List all plans (active + inactive) */
    public function index()
    {
        return response()->json(
            SubscriptionPlan::orderBy('sort_order')->get()
        );
    }

    /** Create a new plan */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'          => 'required|string|alpha_dash|unique:subscription_plans,name',
            'label'         => 'required|string|max:80',
            'price_monthly' => 'nullable|numeric|min:0',
            'price_yearly'  => 'nullable|numeric|min:0',
            'currency'      => 'nullable|string|max:10',
            'limits'        => 'required|array',
            'is_active'     => 'boolean',
            'sort_order'    => 'nullable|integer|min:0',
        ]);

        $plan = SubscriptionPlan::create($data);

        return response()->json($plan, 201);
    }

    /** Update an existing plan's pricing and/or limits */
    public function update(Request $request, SubscriptionPlan $plan)
    {
        $data = $request->validate([
            'label'         => 'sometimes|string|max:80',
            'price_monthly' => 'nullable|numeric|min:0',
            'price_yearly'  => 'nullable|numeric|min:0',
            'currency'      => 'nullable|string|max:10',
            'limits'        => 'sometimes|array',
            'is_active'     => 'sometimes|boolean',
            'sort_order'    => 'nullable|integer|min:0',
        ]);

        $plan->update($data);
        PlanLimit::bustCache($plan->name);

        return response()->json($plan->fresh());
    }

    /** Remove a custom plan (cannot delete built-in basic/pro) */
    public function destroy(SubscriptionPlan $plan)
    {
        if (in_array($plan->name, ['basic', 'pro'])) {
            return response()->json(['message' => 'Cannot delete built-in plans.'], 422);
        }

        if (Tenant::where('plan', $plan->name)->exists()) {
            return response()->json(['message' => 'Plan is in use by tenants. Deactivate it instead.'], 422);
        }

        $plan->delete();
        PlanLimit::bustCache($plan->name);

        return response()->json(['message' => 'Plan deleted.']);
    }

    /**
     * Assign a plan directly to a tenant (admin override / manual upgrade).
     * Records a subscription history entry.
     */
    public function assignToTenant(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'plan'                  => 'required|string|exists:subscription_plans,name',
            'subscription_ends_at'  => 'nullable|date',
            'note'                  => 'nullable|string|max:500',
        ]);

        $fromPlan = $tenant->plan;

        $tenant->update([
            'plan'                  => $data['plan'],
            'subscription_ends_at'  => $data['subscription_ends_at'] ?? null,
            'is_active'             => true,
        ]);

        // Determine event type
        $planOrder = ['basic' => 1, 'pro' => 2, 'custom' => 3];
        $fromOrder = $planOrder[$fromPlan] ?? 1;
        $toOrder   = $planOrder[$data['plan']] ?? 1;

        $eventType = match (true) {
            $fromPlan === $data['plan'] => 'renewal',
            $toOrder > $fromOrder       => 'upgrade',
            default                     => 'downgrade',
        };

        SubscriptionHistory::create([
            'tenant_id'    => $tenant->id,
            'event_type'   => $eventType,
            'from_plan'    => $fromPlan,
            'to_plan'      => $data['plan'],
            'effective_at' => now(),
            'meta'         => ['note' => $data['note'] ?? "Assigned by admin (ID: " . Auth::id() . ")"],
        ]);

        return response()->json([
            'message' => "Tenant plan updated to {$data['plan']}.",
            'tenant'  => $tenant->fresh(),
        ]);
    }
}
