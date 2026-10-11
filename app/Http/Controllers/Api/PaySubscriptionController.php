<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Services\Pay\PayClient;
use App\Services\Pay\PaySubscriptionActivator;
use App\Services\Pay\PayUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/** Paying for a plan through Zinnvy Pay: start a checkout, then confirm it when the customer returns. */
class PaySubscriptionController extends Controller
{
    public function __construct(private PayClient $pay, private PaySubscriptionActivator $activator) {}

    public function checkout(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! in_array($user->role, ['Administrator', 'SuperAdmin'], true)) {
            abort(403, 'Only a workspace Administrator can change the plan.');
        }
        if (! PayClient::configured()) {
            return response()->json(['message' => 'Online payment is not available yet. Please open a billing ticket.'], 503);
        }

        $data = $request->validate(['plan' => 'required|string', 'cadence' => 'required|in:monthly,yearly']);
        $plan = SubscriptionPlan::findByName($data['plan']);
        $price = $plan?->{'price_'.$data['cadence']};
        if (! $plan || ! $price) {
            return response()->json(['message' => 'That plan can’t be bought online.'], 422);
        }

        $tenant = $user->tenant;
        $reference = 'inv-'.$tenant->id.'-'.now()->format('YmdHis').'-'.substr(bin2hex(random_bytes(3)), 0, 6);

        try {
            $payment = $this->pay->createPayment([
                'amount' => (int) round($price * 100),
                'currency' => $plan->currency,
                'description' => "Zinnvy Inventory {$plan->label} ({$data['cadence']})",
                'reference' => $reference,
                'product' => PaySubscriptionActivator::PRODUCT,
                'customer_email' => $user->email,
                'customer_name' => $user->name,
                // So accounts.zinnvy.com can show this payment in the payer's billing history.
                'payer_user_sub' => $user->zinnvy_sub,
                'return_url' => rtrim((string) config('services.frontend_url'), '/').'/dashboard/settings/plans/?pay=return',
                'metadata' => ['tenant_id' => (string) $tenant->id, 'plan' => $plan->name, 'cadence' => $data['cadence']],
            ], $reference);
        } catch (PayUnavailable $e) {
            Log::error('Pay checkout failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Payments are unavailable right now. Please try again shortly.'], 502);
        }

        // Remember which payment this workspace is waiting on; /confirm uses it, never client input.
        $tenant->update(['settings' => array_merge($tenant->settings ?? [], ['pending_pay_id' => $payment['id']])]);

        return response()->json(['checkout_url' => $payment['checkout_url']]);
    }

    /** Called when the customer returns from checkout. Asks Pay for the truth; the webhook may already have applied it. */
    public function confirm(): JsonResponse
    {
        $tenant = Auth::user()->tenant;
        $pending = $tenant->settings['pending_pay_id'] ?? null;
        if (! $pending) {
            return response()->json(['status' => 'none', 'tenant' => $tenant->fresh()]);
        }

        try {
            $payment = $this->pay->getPayment($pending);
        } catch (PayUnavailable) {
            return response()->json(['status' => 'unknown', 'message' => 'We couldn’t check the payment yet. It will apply automatically once confirmed.'], 202);
        }

        // The payment must be ours: a pending id is only ever set by checkout() for this tenant.
        if (($payment['metadata']['tenant_id'] ?? null) !== (string) $tenant->id) {
            abort(403);
        }

        $result = $this->activator->activate($payment);
        $status = $payment['status'];
        if (in_array($status, ['failed', 'canceled'], true)) {
            $tenant->update(['settings' => collect($tenant->settings)->except('pending_pay_id')->all()]);
        }

        return response()->json(['status' => $status, 'applied' => $result, 'tenant' => $tenant->fresh()]);
    }
}
