<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispatch\Job;
use App\Models\Dispatch\Offer;
use App\Models\Dispatch\Rider;
use App\Services\Dispatch\DispatchService;
use App\Services\Pay\DispatchPay;
use App\Services\Pay\PayRejected;
use App\Services\Pay\PayUnavailable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Everything the rider's phone app calls. */
class RiderController extends Controller
{
    public function __construct(private DispatchService $dispatch, private DispatchPay $pay) {}

    private function rider(Request $request): Rider
    {
        return $request->attributes->get('rider');
    }

    public static function payload(Rider $r): array
    {
        return $r->only(['id', 'name', 'phone', 'email', 'status', 'vehicle', 'plate', 'guarantor_name', 'guarantor_phone', 'review_note', 'online', 'deliveries'])
            + ['has_documents' => (bool) ($r->id_photo_path && $r->selfie_path && $r->id_number)];
    }

    public function me(Request $request)
    {
        return response()->json(self::payload($this->rider($request)));
    }

    /** First-time details, sent for Zinnvy to review. Can be re-sent while pending or after a rejection note. */
    public function onboard(Request $request)
    {
        $rider = $this->rider($request);
        abort_if(in_array($rider->status, ['active'], true), 422, 'You are already approved.');
        $data = $request->validate([
            'phone' => 'required|string|max:20',
            'vehicle' => 'required|in:bike,motorbike,car,van',
            'plate' => 'nullable|string|max:20',
            'id_number' => 'required|string|max:40',
            'guarantor_name' => 'required|string|max:80',
            'guarantor_phone' => 'required|string|max:20',
            'id_photo' => ($rider->id_photo_path ? 'nullable' : 'required').'|image|max:8192',
            'selfie' => ($rider->selfie_path ? 'nullable' : 'required').'|image|max:8192',
        ]);

        $paths = [];
        foreach (['id_photo' => 'id_photo_path', 'selfie' => 'selfie_path'] as $field => $column) {
            if ($request->hasFile($field)) {
                $paths[$column] = $request->file($field)->storeAs("dispatch/riders/{$rider->id}", $field.'-'.now()->timestamp.'.'.$request->file($field)->extension(), 'media');
            }
        }
        $rider->update(array_merge(collect($data)->only(['phone', 'vehicle', 'plate', 'id_number', 'guarantor_name', 'guarantor_phone'])->all(), $paths, ['status' => 'pending_review', 'review_note' => null]));

        return response()->json(self::payload($rider->refresh()));
    }

    public function online(Request $request)
    {
        $rider = $this->rider($request);
        abort_unless($rider->isActive(), 403, 'You can go online once Zinnvy approves you.');
        $data = $request->validate(['online' => 'required|boolean', 'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180']);
        $rider->update(['online' => $data['online'], 'last_seen_at' => now(), 'last_lat' => $data['lat'] ?? $rider->last_lat, 'last_lng' => $data['lng'] ?? $rider->last_lng]);

        return response()->json(self::payload($rider));
    }

    private function jobView(Job $job, ?Offer $offer = null): array
    {
        $showCustomer = in_array($job->status, ['assigned', 'picked_up'], true);

        return [
            'id' => $job->id, 'code' => $job->code, 'status' => $job->status, 'pay_status' => $job->pay_status,
            'pickup' => ['name' => $job->pickup_name, 'address' => $job->pickup_address, 'phone' => $job->pickup_phone, 'lat' => $job->pickup_lat, 'lng' => $job->pickup_lng],
            // The customer's phone and exact spot are only shown to the rider who took the job.
            'dropoff' => ['name' => $showCustomer ? $job->dropoff_name : null, 'phone' => $showCustomer ? $job->dropoff_phone : null,
                'address' => $job->dropoff_address, 'landmark' => $job->dropoff_landmark, 'lat' => $job->dropoff_lat, 'lng' => $job->dropoff_lng],
            'distance_km' => $job->distance_km,
            'you_earn' => $job->rider_share / 100,
            'customer_pays' => $job->total() / 100,
            'order_number' => $job->order?->number,
            'offer_expires_at' => $offer?->expires_at?->toIso8601String(),
            'failure_reason' => $job->failure_reason,
            'delivered_at' => $job->delivered_at?->toIso8601String(),
        ];
    }

    public function jobs(Request $request)
    {
        $rider = $this->rider($request);
        $offers = Offer::with('job.order')->where('rider_id', $rider->id)->where('status', 'offered')->where('expires_at', '>', now())
            ->whereHas('job', fn ($q) => $q->where('status', 'searching'))->get()->map(fn ($o) => $this->jobView($o->job, $o));
        $active = Job::with('order')->where('rider_id', $rider->id)->whereIn('status', ['assigned', 'picked_up'])->orderBy('assigned_at')->get()->map(fn ($j) => $this->jobView($j));
        $recent = Job::with('order')->where('rider_id', $rider->id)->whereIn('status', ['delivered', 'failed'])->latest('closed_at')->limit(15)->get()->map(fn ($j) => $this->jobView($j));

        return response()->json(['offers' => $offers, 'active' => $active, 'recent' => $recent]);
    }

    private function mine(Request $request, int $id): Job
    {
        return Job::with('order')->findOrFail($id);
    }

    public function accept(Request $request, int $id)
    {
        return response()->json($this->jobView($this->dispatch->accept($this->rider($request), Job::findOrFail($id))->load('order')));
    }

    public function decline(Request $request, int $id)
    {
        $this->dispatch->decline($this->rider($request), Job::findOrFail($id));

        return response()->json(['ok' => true]);
    }

    public function pickup(Request $request, int $id)
    {
        return response()->json($this->jobView($this->dispatch->pickUp($this->rider($request), $this->mine($request, $id))));
    }

    public function collect(Request $request, int $id)
    {
        return $this->payment(fn () => $this->dispatch->collectPayment($this->rider($request), $this->mine($request, $id)));
    }

    public function sendPayLink(Request $request, int $id)
    {
        return $this->payment(function () use ($request, $id) {
            $this->dispatch->sendPayLink($this->rider($request), $this->mine($request, $id));

            return ['sent' => true];
        });
    }

    public function checkPayment(Request $request, int $id)
    {
        return $this->payment(function () use ($request, $id) {
            $job = $this->mine($request, $id);
            abort_unless($job->rider_id === $this->rider($request)->id, 403);

            return ['pay_status' => $this->dispatch->refreshPayment($job)->pay_status];
        });
    }

    public function deliver(Request $request, int $id)
    {
        $data = $request->validate(['code' => 'required|string|max:8']);

        return $this->payment(fn () => $this->jobView($this->dispatch->deliver($this->rider($request), $this->mine($request, $id), $data['code'])));
    }

    public function fail(Request $request, int $id)
    {
        $data = $request->validate(['reason' => 'required|string|max:190']);

        return response()->json($this->jobView($this->dispatch->fail($this->rider($request), $this->mine($request, $id), $data['reason'])));
    }

    // ------------------------------------------------------------------ wallet

    private function payment(callable $fn)
    {
        abort_unless(DispatchPay::configured(), 503, 'Payments are not switched on yet.');
        try {
            return response()->json($fn());
        } catch (PayRejected $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (PayUnavailable $e) {
            report($e);

            return response()->json(['message' => 'Zinnvy Pay is unavailable for a moment. Please try again.'], 503);
        }
    }

    public function wallet(Request $request)
    {
        $r = $this->rider($request);

        return $this->payment(fn () => $this->pay->ensureWallet($r->walletRef(), 'rider', $r->name));
    }

    public function entries(Request $request)
    {
        $r = $this->rider($request);

        return $this->payment(function () use ($r, $request) {
            $this->pay->ensureWallet($r->walletRef(), 'rider', $r->name);

            return $this->pay->entries($r->walletRef(), $request->query('cursor'));
        });
    }

    public function withdrawals(Request $request)
    {
        $r = $this->rider($request);

        return $this->payment(function () use ($r) {
            $this->pay->ensureWallet($r->walletRef(), 'rider', $r->name);

            return $this->pay->withdrawals($r->walletRef());
        });
    }

    public function setPayout(Request $request)
    {
        $r = $this->rider($request);
        $data = $request->validate(['network' => 'required|string|max:20', 'number' => 'required|string|max:20']);

        return $this->payment(function () use ($r, $data) {
            $this->pay->ensureWallet($r->walletRef(), 'rider', $r->name);

            return $this->pay->setPayout($r->walletRef(), $data['network'], $data['number']);
        });
    }

    public function withdraw(Request $request)
    {
        $r = $this->rider($request);
        $data = $request->validate(['amount' => 'required|numeric|min:1', 'key' => 'required|string|max:60']);

        return $this->payment(fn () => $this->pay->withdraw($r->walletRef(), (int) round($data['amount'] * 100), 'rider'.$r->id.'-'.$data['key']));
    }
}
