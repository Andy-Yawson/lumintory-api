<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;

class PlanController extends Controller
{
    /**
     * Public endpoint — returns active plans with pricing and limits.
     * Used by the tenant settings/upgrade page and Zinnvy AI tools.
     */
    public function index()
    {
        $plans = SubscriptionPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'label', 'price_monthly', 'price_yearly', 'currency', 'limits']);

        return response()->json(['plans' => $plans]);
    }
}
