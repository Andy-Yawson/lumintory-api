<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\SmsCredit;
use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class SmsController extends Controller
{
    public function send(Request $request, SmsService $sms)
    {
        $data = $request->validate([
            'message' => 'required|string|max:160',
            'recipients' => 'required|array|min:1',
            'recipients.*' => 'string',
        ]);

        return $this->charge_and_queue($sms, collect($data['recipients']), $data['message'], 'SMS queued.');
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

        if ($data['mode'] === 'selected') {
            $recipients = collect($data['recipients'] ?? []);
        } else {
            $query = Customer::where('tenant_id', Auth::user()->tenant_id);

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

        return $this->charge_and_queue($sms, $recipients, $data['message'], 'Bulk SMS has been queued.');
    }

    /**
     * Normalise + de-duplicate the numbers, charge exactly that many messages, and queue exactly
     * those numbers, in one transaction: credits are never taken for messages that aren't sent.
     */
    private function charge_and_queue(SmsService $sms, $recipients, string $message, string $okMessage)
    {
        $tenant = Auth::user()->tenant;

        $recipients = collect($recipients)
            ->map(fn ($n) => SmsService::normalizePhone($n))
            ->filter()
            ->unique()
            ->values();

        if ($recipients->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No valid phone numbers to send to.'], 422);
        }

        // Charge for what is actually sent: the GSM-safe text (₵ → GHS etc.), not the raw input.
        $message = SmsService::toGsm($message);
        $segments = SmsService::segments($message);
        $needed = $segments * $recipients->count();

        return DB::transaction(function () use ($sms, $tenant, $recipients, $message, $segments, $needed, $okMessage) {
            $credits = SmsCredit::lockForUpdate()->firstOrCreate(['tenant_id' => $tenant->id], ['credits' => 0]);

            if ($credits->credits < $needed) {
                return response()->json([
                    'success' => false,
                    'message' => "Not enough SMS credits: this needs {$needed}, you have {$credits->credits}.",
                ], 422);
            }

            $credits->decrement('credits', $needed);

            foreach ($recipients as $number) {
                $sms->queueSms(tenantId: $tenant->id, from: $tenant->name, to: $number, message: $message);
            }

            return response()->json([
                'success' => true,
                'message' => $okMessage,
                'recipients' => $recipients->count(),
                'segments_per_recipient' => $segments,
                'charged_sms' => $needed,
                'remaining_credits' => $credits->fresh()->credits,
            ]);
        });
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
