<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AdminSubscriptionController extends Controller
{
    public function summary(Request $request)
    {
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();
        $startOfPrevMonth = $now->copy()->subMonth()->startOfMonth();
        $endOfPrevMonth = $now->copy()->subMonth()->endOfMonth();

        $plansConfig = config('subscriptions.plans', []);

        // Total tenants by plan
        $totalByPlan = Tenant::selectRaw('plan, COUNT(*) as total')
            ->groupBy('plan')
            ->pluck('total', 'plan');

        $totalTenants = Tenant::count();

        // New tenants this month
        $newThisMonth = Tenant::whereBetween('created_at', [$startOfMonth, $endOfMonth])->count();

        // "Churned" this month (very rough v1):
        // tenants that were active but subscription_ends_at moved into past this month
        $churnThisMonth = Tenant::where('subscription_ends_at', '<', $now)
            ->whereBetween('subscription_ends_at', [$startOfMonth, $endOfMonth])
            ->count();

        // MRR estimate: assume yearly price and spread over 12 if active
        $activeTenants = Tenant::where('is_active', true)->get();
        $mrrEstimate = 0;
        $mrrByPlan = [];

        foreach ($activeTenants as $tenant) {
            $plan = $tenant->plan;
            $config = $plansConfig[$plan] ?? null;
            if (!$config) {
                continue;
            }

            // Use monthly if set, else yearly/12
            $monthly = $config['price_monthly'] ?? null;
            $yearly = $config['price_yearly'] ?? null;

            $tenantMrr = 0;
            if ($monthly) {
                $tenantMrr = $monthly;
            } elseif ($yearly) {
                $tenantMrr = $yearly / 12;
            }

            $mrrEstimate += $tenantMrr;
            if (!isset($mrrByPlan[$plan])) {
                $mrrByPlan[$plan] = 0;
            }
            $mrrByPlan[$plan] += $tenantMrr;
        }

        // Expiring soon (next 30 days)
        $expiringSoonCount = Tenant::where('is_active', true)
            ->whereBetween('subscription_ends_at', [$now, $now->copy()->addDays(30)])
            ->count();

        return response()->json([
            'totals' => [
                'tenants' => $totalTenants,
                'by_plan' => $totalByPlan,
            ],
            'mrr' => [
                'total' => round($mrrEstimate, 2),
                'by_plan' => array_map(fn($v) => round($v, 2), $mrrByPlan),
                'currency' => 'GHS',
            ],
            'activity' => [
                'new_this_month' => $newThisMonth,
                'churn_this_month' => $churnThisMonth,
            ],
            'expiring' => [
                'expiring_30_days' => $expiringSoonCount,
            ],
            'note' => 'All metrics are approximations; full subscription history not yet implemented.',
        ]);
    }


    public function tenants(Request $request)
    {
        $query = Tenant::query();

        if ($plan = $request->get('plan')) {
            $query->where('plan', $plan);
        }

        if (!is_null($request->get('is_active'))) {
            $isActive = filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (!is_null($isActive)) {
                $query->where('is_active', $isActive);
            }
        }

        // Expiring / status filter
        if ($request->get('status') === 'expiring_30_days') {
            $now = Carbon::now();
            $query->where('is_active', true)
                ->whereBetween('subscription_ends_at', [$now, $now->copy()->addDays(30)]);
        }

        $query->orderBy('subscription_ends_at', 'asc');

        $tenants = $query->paginate($request->get('per_page', 15));

        return response()->json($tenants);
    }


    public function history(Request $request)
    {
        $query = SubscriptionHistory::with('tenant');

        if ($eventType = $request->get('event_type')) {
            $query->where('event_type', $eventType);
        }

        if ($plan = $request->get('plan')) {
            $query->where('to_plan', $plan);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('effective_at', '>=', $request->get('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('effective_at', '<=', $request->get('end_date'));
        }

        $query->orderBy('effective_at', 'desc');

        $perPage = $request->get('per_page', 20);

        return response()->json(
            $query->paginate($perPage)
        );
    }

    public function historyByTenant(Request $request, Tenant $tenant)
    {
        $histories = $tenant->subscriptionHistories()
            ->orderBy('effective_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json($histories);
    }


    public function charts(Request $request)
    {
        $monthsBack = (int) $request->get('months', 12);
        $now = Carbon::now();
        $start = $now->copy()->subMonths($monthsBack - 1)->startOfMonth();
        $end = $now->copy()->endOfMonth();

        // Group by year-month + event_type
        $raw = SubscriptionHistory::selectRaw("
                DATE_FORMAT(effective_at, '%Y-%m') as ym,
                event_type,
                COUNT(*) as total
            ")
            ->whereBetween('effective_at', [$start, $end])
            ->groupBy('ym', 'event_type')
            ->orderBy('ym')
            ->get();

        // Build a normalized structure with all months in range
        $months = [];
        $cursor = $start->copy();
        while ($cursor <= $end) {
            $key = $cursor->format('Y-m');
            $months[$key] = [
                'month' => $cursor->format('M Y'),
                'ym' => $key,
                'signup' => 0,
                'upgrade' => 0,
                'downgrade' => 0,
                'renewal' => 0,
                'cancel' => 0,
                'reactivate' => 0,
            ];
            $cursor->addMonth();
        }

        foreach ($raw as $row) {
            if (!isset($months[$row->ym])) {
                continue;
            }
            $type = $row->event_type;
            $months[$row->ym][$type] = (int) $row->total;
        }

        return response()->json(array_values($months));
    }

}
