<?php

namespace App\Http\Middleware;

use App\Models\SupportTicket;
use App\Services\PlanLimit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSupportTicketLimit
{
    public function handle($request, Closure $next)
    {
        $user = $request->user();
        $tenant = $user->tenant;

        if (!$tenant) {
            abort(403, 'No tenant context.');
        }

        $maxTickets = PlanLimit::getLimit($tenant, 'support_tickets_per_month');

        if ($maxTickets === null) {
            return $next($request); // unlimited for custom
        }

        $count = SupportTicket::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        if ($count >= $maxTickets) {
            return response()->json([
                'success' => false,
                'message' => "You have reached your {$tenant->plan} plan limit for support tickets this month.",
            ], 429);
        }

        return $next($request);
    }
}
