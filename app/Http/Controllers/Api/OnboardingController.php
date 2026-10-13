<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The dashboard's "Get started" checklist. Every step is derived from real workspace data,
 * so it ticks itself off and looks the same for every Administrator on every device.
 */
class OnboardingController extends Controller
{
    public function show()
    {
        $user = Auth::user();
        $tenant = $user->tenant;
        $tid = $tenant->id;

        $products = Product::withoutGlobalScopes()->where('tenant_id', $tid);

        $steps = [
            ['key' => 'products', 'done' => (clone $products)->exists(), 'optional' => false],
            ['key' => 'restock', 'done' => (clone $products)->where('min_stock_threshold', '>', 0)->exists(), 'optional' => false],
            ['key' => 'sale', 'done' => Sale::withoutGlobalScopes()->where('tenant_id', $tid)->exists(), 'optional' => false],
            ['key' => 'photos', 'done' => ProductImage::where('tenant_id', $tid)->exists(), 'optional' => true],
            ['key' => 'team', 'done' => User::where('tenant_id', $tid)->count() > 1, 'optional' => true],
        ];

        return response()->json([
            'steps' => $steps,
            'dismissed' => ! empty($tenant->settings['onboarding_dismissed_at']),
            // Only Administrators can act on most steps, so only they see the card.
            'visible' => $user->role === 'Administrator',
        ]);
    }

    public function dismiss()
    {
        $user = Auth::user();
        abort_unless($user->role === 'Administrator', 403);

        $tenant = $user->tenant;
        $settings = $tenant->settings ?? [];
        $settings['onboarding_dismissed_at'] = now()->toIso8601String();
        $tenant->settings = $settings;
        $tenant->save();

        return response()->json(['dismissed' => true]);
    }
}
