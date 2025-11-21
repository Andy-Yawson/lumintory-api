<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsCredit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SmsController extends Controller
{
    public function send(Request $request)
    {
        $data = $request->validate([
            'message' => 'required|string|max:160',
            'recipients' => 'required|array|min:1',
            'recipients.*' => 'string',
            'sms_count' => 'nullable|integer|min:1',
        ]);

        $smsCredit = $request->attributes->get('sms_credits_model');
        $requestedCount = $request->attributes->get('sms_requested_count', count($data['recipients']));

        // Deduct credits safely (you might want to wrap in transaction if external provider)
        $smsCredit->decrement('credits', $requestedCount);

        // TODO: actually send SMS via your provider
        // For now, just log:
        Log::info('Sending SMS', [
            'tenant_id' => $smsCredit->tenant_id,
            'recipients' => $data['recipients'],
            'message' => $data['message'],
            'count' => $requestedCount,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'SMS queued for sending.',
            'remaining_credits' => $smsCredit->fresh()->credits,
        ]);
    }

    public function sendBulk(Request $request)
    {
        $data = $request->validate([
            'message' => 'required|string|max:320', // allow longer for bulk
            'mode' => 'required|in:selected,all_filtered',
            'recipients' => 'nullable|array',
            'recipients.*' => 'string',
            'customer_ids' => 'nullable|array',
            'customer_ids.*' => 'integer',
            'filters' => 'nullable|array', // e.g. ['search' => 'kwame']
            'sms_count' => 'nullable|integer|min:1',
        ]);


        $tenant = Auth::user()->tenant;

        // 1. Determine recipients list
        if ($data['mode'] === 'selected') {
            // Option A: front-end passes phone numbers directly (recipients)
            $recipients = $data['recipients'] ?? [];

        } else {
            // mode = all_filtered → derive from DB using filters
            $query = \App\Models\Customer::where('tenant_id', $tenant->id);

            if (!empty($data['filters']['search'])) {
                $search = $data['filters']['search'];
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $customers = $query->get();
            $recipients = $customers->pluck('phone')->filter()->values()->all();
        }

        if (empty($recipients)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid recipients found for this bulk SMS.',
            ], 422);
        }

        // 2. Compute SMS segments
        $message = $data['message'];
        $length = mb_strlen($message);

        // Simple approximation: 160 chars per segment
        $segmentsPerRecipient = (int) ceil($length / 160);
        $totalSmsToCharge = $segmentsPerRecipient * count($recipients);

        // Let middleware override this if you want
        $dataSmsCount = $data['sms_count'] ?? null;

        // Prefer middleware-provided value if present
        $effectiveSmsCount = $request->attributes->get('sms_requested_count', $dataSmsCount ?? $totalSmsToCharge);

        /** @var \App\Models\SmsCredit $smsCredit */
        $smsCredit = $request->attributes->get('sms_credits_model');

        // 3. Deduct credits
        $smsCredit->decrement('credits', $effectiveSmsCount);

        // 4. Actually send SMS (or queue) – here we just log
        Log::info('Sending BULK SMS', [
            'tenant_id' => $smsCredit->tenant_id,
            'mode' => $data['mode'],
            'recipients' => $recipients,
            'message' => $message,
            'segments' => $segmentsPerRecipient,
            'total_count' => $effectiveSmsCount,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Bulk SMS queued for sending.',
            'recipients_count' => count($recipients),
            'segments_per_user' => $segmentsPerRecipient,
            'charged_sms' => $effectiveSmsCount,
            'remaining_credits' => $smsCredit->fresh()->credits,
        ]);
    }

    public function getSMSCredit(Request $request)
    {
        $credit = SmsCredit::where('tenant_id', Auth::user()->tenant->id)->first();
        return response()->json(['credits' => $credit->credits]);
    }
}
