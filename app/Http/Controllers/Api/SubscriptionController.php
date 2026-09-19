<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SubscriptionController extends Controller
{
    public function update(Request $request)
    {
        $tenant = Auth::user()->tenant;
        $data = $request->validate([
            'plan' => 'required|in:monthly,yearly,free',
        ]);

        // 'monthly'/'yearly'/'free' describe billing cadence, not a feature
        // tier — tenants.plan is a DB enum('basic','pro','custom'). Writing
        // these values into it directly (as this used to) fails at the
        // database level on every call to anything but 'free'.
        $months = $data['plan'] === 'yearly' ? 12 : 1;
        $tenant->update([
            'plan' => $data['plan'] === 'free' ? 'basic' : 'pro',
            'subscription_ends_at' => now()->addMonths($months),
            'is_active' => true,
        ]);

        return response()->json(['message' => 'Subscription activated!', 'success' => true]);
    }
}
