<?php

namespace App\Http\Middleware;

use App\Models\SmsCredit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckSmsCredits
{
    public function handle(Request $request, Closure $next, string $countField = 'sms_count')
    {
        $user = Auth::user();

        if (!$user || !$user->tenant) {
            return response()->json([
                'success' => false,
                'message' => 'No tenant found.',
            ], 404);
        }

        $tenant = $user->tenant;
        $plan = $tenant->plan ?? 'basic';

        $planLimits = config("plan_limits.$plan", []);

        $maxPerBatch = $planLimits['sms'] ?? null;

        $smsCredit = SmsCredit::firstOrCreate(
            ['tenant_id' => $tenant->id],
            ['credits' => 0]
        );

        // Determine how many SMS we plan to send
        $requestedCount = (int) $request->input($countField, 0);

        if ($requestedCount === 0 && $request->has('recipients')) {
            $recipients = $request->input('recipients', []);
            if (is_array($recipients)) {
                $requestedCount = count($recipients);
            }
        }

        if ($requestedCount <= 0) {
            $requestedCount = 1;
        }

        // Enforce per-batch limit if plan defines it
        if ($maxPerBatch !== null && $requestedCount > $maxPerBatch) {
            return response()->json([
                'success' => false,
                'error' => 'sms_batch_limit',
                'message' => "Your plan allows sending up to {$maxPerBatch} SMS per batch.",
                'requested' => $requestedCount,
                'plan' => $plan,
            ], 403);
        }

        // Enforce credit check
        if ($smsCredit->credits < $requestedCount) {
            return response()->json([
                'success' => false,
                'error' => 'insufficient_sms_credits',
                'message' => "You don't have enough SMS credits to complete this action.",
                'credits' => $smsCredit->credits,
                'required' => $requestedCount,
                'plan' => $plan,
            ], 403);
        }

        // Attach to request for controller
        $request->attributes->set('sms_tenant', $tenant);
        $request->attributes->set('sms_credits_model', $smsCredit);
        $request->attributes->set('sms_requested_count', $requestedCount);

        return $next($request);
    }
}
