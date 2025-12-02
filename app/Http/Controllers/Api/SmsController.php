<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\SmsCredit;
use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SmsController extends Controller
{
    public function send(Request $request, SmsService $sms)
    {
        $data = $request->validate([
            'message' => 'required|string|max:160',
            'recipients' => 'required|array|min:1'
        ]);

        $tenant = Auth::user()->tenant;
        $credits = SmsCredit::where('tenant_id', $tenant->id)->first();

        $segments = $sms->calculateSegments($data['message']);
        $totalRequired = count($data['recipients']) * $segments;

        if ($credits->credits < $totalRequired) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient SMS credits.'
            ], 422);
        }

        $credits->decrement('credits', $totalRequired);

        foreach ($data['recipients'] as $recipient) {
            $sms->queueSms(
                tenantId: $tenant->id,
                from: $tenant->name,
                to: $recipient,
                message: $data['message']
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'SMS queued.',
            'remaining_credits' => $credits->fresh()->credits
        ]);
    }

    public function sendBulk(Request $request, SmsService $sms)
    {
        $data = $request->validate([
            'message' => 'required|string|max:500',
            'mode' => 'required|in:selected,all_filtered',
            'recipients' => 'nullable|array',
            'recipients.*' => 'string',
            'customer_ids' => 'nullable|array',
            'customer_ids.*' => 'integer',
            'filters' => 'nullable|array',
        ]);

        $tenant = Auth::user()->tenant;

        if ($data['mode'] === 'selected') {
            $recipients = collect($data['recipients'] ?? []);
        } else {
            $query = Customer::where('tenant_id', $tenant->id);

            if (!empty($data['filters']['search'])) {
                $search = $data['filters']['search'];
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $recipients = $query->pluck('phone');
        }

        // Remove nulls and invalid
        $recipients = $recipients
            ->map(fn($n) => SmsService::normalizePhone($n))
            ->filter()
            ->unique()
            ->values();

        if ($recipients->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No valid recipients found.',
            ], 422);
        }

        $message = $data['message'];
        $segments = SmsService::segments($message);
        $totalSmsNeeded = $segments * $recipients->count();

        $smsCredit = SmsCredit::where('tenant_id', $tenant->id)->first();

        if ($smsCredit->credits < $totalSmsNeeded) {
            return response()->json([
                'success' => false,
                'message' => "Insufficient SMS credits. Required: {$totalSmsNeeded}, Available: {$smsCredit->credits}",
            ], 422);
        }

        $smsCredit->decrement('credits', $totalSmsNeeded);

        foreach ($data['recipients'] as $recipient) {
            $sms->queueSms(
                tenantId: $tenant->id,
                from: $tenant->name,
                to: $recipient,
                message: $data['message'],
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Bulk SMS has been queued.',
            'recipients' => $recipients->count(),
            'segments_per_recipient' => $segments,
            'charged_sms' => $totalSmsNeeded,
            'remaining_credits' => $smsCredit->fresh()->credits,
        ]);
    }

    public function getSMSCredit(Request $request)
    {
        $credit = SmsCredit::where('tenant_id', Auth::user()->tenant->id)->first();
        return response()->json(['credits' => $credit->credits]);
    }

    public function deliveryCallback(Request $request)
    {
        if (!$request->message_id)
            return;

        SmsLog::where('provider_message_id', $request->message_id)
            ->update(['status' => $request->status ?? 'delivered']);
    }
}
