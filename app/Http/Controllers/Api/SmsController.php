<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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
}
