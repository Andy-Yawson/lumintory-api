<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispatch\Job;
use Illuminate\Support\Facades\Crypt;

/** What a customer sees when they open their tracking link. The unguessable token in the link is the only credential. */
class DispatchTrackController extends Controller
{
    private const STEPS = ['searching' => 'Finding a rider', 'assigned' => 'Rider on the way to the shop', 'picked_up' => 'On the way to you', 'delivered' => 'Delivered'];

    /** The customer rates their delivery, once. The link they were sent is the credential. */
    public function rate(\Illuminate\Http\Request $request, string $token)
    {
        abort_unless(strlen($token) >= 24, 404);
        $data = $request->validate(['rating' => 'required|integer|between:1,5', 'comment' => 'nullable|string|max:300']);
        $job = Job::where('track_token', $token)->firstOrFail();
        if ($job->status !== 'delivered') {
            return response()->json(['message' => 'You can rate once your order has been delivered.'], 422);
        }
        // One rating per delivery: the conditional update makes two taps at once safe.
        $saved = Job::whereKey($job->id)->whereNull('rating')->update(['rating' => $data['rating'], 'rating_comment' => isset($data['comment']) ? trim(strip_tags($data['comment'])) ?: null : null, 'rated_at' => now()]);
        if (! $saved) {
            return response()->json(['message' => 'You have already rated this delivery. Thank you!'], 422);
        }

        return response()->json(['rating' => ['value' => (int) $data['rating'], 'comment' => $data['comment'] ?? null]], 201);
    }

    public function show(string $token)
    {
        abort_unless(strlen($token) >= 24, 404);
        $job = Job::with(['rider', 'tenant'])->where('track_token', $token)->firstOrFail();
        $order = $job->order;
        $onTheRoad = in_array($job->status, ['assigned', 'picked_up'], true);
        $symbol = $job->tenant->settings['currency_symbol'] ?? 'GH₵';

        $code = null;
        if ($job->status === 'picked_up' && $job->handover_code_enc) {
            try {
                $code = Crypt::decryptString($job->handover_code_enc);
            } catch (\Throwable) {
                $code = null;
            }
        }

        return response()->json([
            'shop' => $job->tenant->name,
            'order_number' => $order?->number,
            'status' => $job->status,
            'steps' => collect(self::STEPS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'currency_symbol' => $symbol,
            'goods' => $job->goods_amount / 100,
            'delivery_fee' => $job->delivery_fee / 100,
            'total' => $job->total() / 100,
            'pay_status' => $job->pay_status,
            // The rider's first name, vehicle and phone are shared only while the delivery is on the road.
            'rider' => $onTheRoad && $job->rider ? ['name' => strtok($job->rider->name, ' '), 'vehicle' => $job->rider->vehicle, 'plate' => $job->rider->plate, 'phone' => $job->rider->phone] : null,
            // Customers can pay from here as well as by the rider's QR code.
            'pay_url' => $job->status === 'picked_up' && $job->pay_status === 'pending' ? $job->pay_checkout_url : null,
            'code' => $job->pay_status === 'paid' ? $code : null,
            'code_hint' => $job->status === 'picked_up' && $job->pay_status !== 'paid' ? 'Your handover code appears here after you pay.' : null,
            'failure_reason' => $job->status === 'failed' ? $job->failure_reason : null,
            'can_rate' => $job->status === 'delivered' && $job->rating === null,
            'rating' => $job->rating !== null ? ['value' => $job->rating, 'comment' => $job->rating_comment] : null,
            'updated_at' => ($job->delivered_at ?? $job->picked_up_at ?? $job->assigned_at ?? $job->created_at)?->toIso8601String(),
        ]);
    }
}
