<?php

namespace App\Services\Dispatch;

use App\Models\Dispatch\Job;
use App\Models\Dispatch\Offer;
use App\Models\Dispatch\Rider;
use App\Models\Shop;
use App\Models\ShopOrder;
use App\Services\Pay\DispatchPay;
use App\Services\Shop\ShopOrderService;
use App\Services\SmsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The life of one delivery: requested by the shop owner, offered to riders, picked up, paid by the customer at the
 * door (into Zinnvy's wallet system, split between shop and rider), and handed over with the customer's code.
 */
class DispatchService
{
    /** A rider can hold this many unfinished jobs at once. */
    public const MAX_ACTIVE_PER_RIDER = 2;
    public const MAX_OFFER_ROUNDS = 3;
    public const MAX_CODE_TRIES = 5;

    public function __construct(private DispatchPay $pay, private ShopOrderService $orders) {}

    // ------------------------------------------------------------------ owner

    /** @throws ValidationException */
    public function request(ShopOrder $order): Job
    {
        $shop = Shop::withoutGlobalScopes()->findOrFail($order->shop_id);
        $fail = fn (string $m) => throw ValidationException::withMessages(['order' => $m]);

        if ($order->fulfilment !== 'delivery' || $order->payment_method !== 'momo_on_delivery') {
            $fail('Only delivery orders paid by Mobile Money on arrival can use a Zinnvy rider.');
        }
        if ($order->status !== 'confirmed') {
            $fail('Confirm the order with the customer first, then request a rider.');
        }
        if (! $shop->rider_delivery || ! $shop->pickup_address) {
            $fail('Add your pickup address in Shop settings and switch on rider delivery first.');
        }
        if ($order->delivery_lat === null || $order->delivery_lng === null) {
            $fail('This order has no map location, so a rider cannot find it.');
        }
        if (Job::where('shop_order_id', $order->id)->whereIn('status', Job::OPEN)->exists() || Job::where('shop_order_id', $order->id)->where('status', 'delivered')->exists()) {
            $fail('This order already has a rider request.');
        }

        $goods = (int) round(((float) $order->subtotal) * 100);
        $fee = (int) $order->delivery_fee;
        $split = Pricing::split($goods, $fee);

        $job = Job::create([
            'code' => $this->newCode(),
            'track_token' => Str::random(32),
            'tenant_id' => $order->tenant_id,
            'shop_order_id' => $order->id,
            'status' => 'searching',
            'pickup_name' => $shop->tenant->name,
            'pickup_address' => $shop->pickup_address,
            'pickup_phone' => $shop->pickup_phone ?: $shop->notify_phone,
            'pickup_lat' => $shop->pickup_lat,
            'pickup_lng' => $shop->pickup_lng,
            'dropoff_name' => $order->customer_name,
            'dropoff_phone' => $order->customer_phone,
            'dropoff_address' => (string) $order->address,
            'dropoff_landmark' => $order->landmark,
            'dropoff_lat' => $order->delivery_lat,
            'dropoff_lng' => $order->delivery_lng,
            'distance_km' => $order->distance_km,
            'goods_amount' => $goods,
            'delivery_fee' => $fee,
            'rider_share' => $split['rider_share'],
            'goods_commission' => $split['goods_commission'],
            'rates' => $split['rates'],
        ]);
        $this->offerToRiders($job);
        $job->refresh();
        $this->sms($job, $job->dropoff_phone, "Your {$job->pickup_name} order #{$order->number} is being arranged for delivery. Follow it here: {$job->trackUrl()}");

        return $job;
    }

    public function cancel(Job $job): Job
    {
        $this->transition($job, ['searching', 'assigned'], fn (Job $j) => $j->update(['status' => 'cancelled', 'closed_at' => now()]), 'This delivery can no longer be cancelled.');
        $job->offers()->where('status', 'offered')->update(['status' => 'withdrawn']);
        if ($job->rider) {
            $this->sms($job, $job->rider->phone, "Zinnvy delivery {$job->code} was cancelled by the shop. Sorry for the trouble.");
        }

        return $job->refresh();
    }

    // ------------------------------------------------------------------ offers

    /** Offers the job to every online, active rider with room for it. Returns how many were asked. */
    public function offerToRiders(Job $job): int
    {
        $job->increment('offer_rounds');
        $busy = Job::whereIn('status', ['assigned', 'picked_up'])->whereNotNull('rider_id')->selectRaw('rider_id, count(*) n')->groupBy('rider_id')->pluck('n', 'rider_id');
        $riders = Rider::where('status', 'active')->where('online', true)->get()->filter(fn ($r) => ($busy[$r->id] ?? 0) < self::MAX_ACTIVE_PER_RIDER);

        $expires = now()->addSeconds((int) Settings::get('offer_seconds'));
        foreach ($riders as $rider) {
            Offer::updateOrCreate(['job_id' => $job->id, 'rider_id' => $rider->id], ['status' => 'offered', 'expires_at' => $expires]);
            $this->sms($job, $rider->phone, "New Zinnvy delivery {$job->code}: {$job->pickup_name} to {$job->dropoff_address}, GH₵ ".number_format($job->rider_share / 100, 2).' for you. Open Zinnvy Rider to accept.');
        }

        return $riders->count();
    }

    /** Run every minute: let unanswered offers lapse, and ask again (up to a few rounds) if nobody took the job. */
    public function tick(): void
    {
        Offer::where('status', 'offered')->where('expires_at', '<=', now())->update(['status' => 'expired']);

        Job::where('status', 'searching')->whereDoesntHave('offers', fn ($q) => $q->where('status', 'offered'))->get()->each(function (Job $job) {
            if ($job->offer_rounds < self::MAX_OFFER_ROUNDS) {
                $this->offerToRiders($job);

                return;
            }
            if ($job->offer_rounds === self::MAX_OFFER_ROUNDS) {
                $job->increment('offer_rounds');   // tell the owner once
                $this->sms($job, optional(Shop::withoutGlobalScopes()->find($job->order->shop_id))->notify_phone, "No Zinnvy rider has accepted delivery {$job->code} yet. We are looking, or you can arrange your own delivery.");
            }
        });
    }

    public function accept(Rider $rider, Job $job): Job
    {
        DB::transaction(function () use ($rider, $job) {
            $locked = Job::whereKey($job->id)->lockForUpdate()->firstOrFail();
            $offer = Offer::where('job_id', $locked->id)->where('rider_id', $rider->id)->lockForUpdate()->first();
            if ($locked->status !== 'searching') {
                throw ValidationException::withMessages(['job' => 'Another rider already took this delivery.']);
            }
            if (! $offer || $offer->status !== 'offered' || $offer->expires_at->isPast()) {
                throw ValidationException::withMessages(['job' => 'This offer has expired.']);
            }
            if (Job::where('rider_id', $rider->id)->whereIn('status', ['assigned', 'picked_up'])->count() >= self::MAX_ACTIVE_PER_RIDER) {
                throw ValidationException::withMessages(['job' => 'Finish a delivery before taking another.']);
            }
            $this->assignTo($locked, $rider);
            $offer->update(['status' => 'accepted']);
        });

        return $job->refresh();
    }

    public function decline(Rider $rider, Job $job): void
    {
        Offer::where('job_id', $job->id)->where('rider_id', $rider->id)->where('status', 'offered')->update(['status' => 'declined']);
    }

    /** Staff put a job on a specific rider, skipping the offer. */
    public function assign(Job $job, Rider $rider): Job
    {
        if (! $rider->isActive()) {
            throw ValidationException::withMessages(['rider' => 'That rider is not active.']);
        }
        DB::transaction(function () use ($job, $rider) {
            $locked = Job::whereKey($job->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['searching', 'assigned'], true)) {
                throw ValidationException::withMessages(['job' => 'This delivery has already started or finished.']);
            }
            $this->assignTo($locked, $rider);
        });

        return $job->refresh();
    }

    private function assignTo(Job $job, Rider $rider): void
    {
        $job->update(['rider_id' => $rider->id, 'status' => 'assigned', 'assigned_at' => now()]);
        Offer::where('job_id', $job->id)->where('rider_id', '!=', $rider->id)->where('status', 'offered')->update(['status' => 'withdrawn']);
        $shop = Shop::withoutGlobalScopes()->find($job->order->shop_id);
        $this->sms($job, $shop?->notify_phone, "Zinnvy rider {$rider->name} ({$rider->phone}) is coming to collect order #{$job->order->number} (delivery {$job->code}).");
    }

    // ------------------------------------------------------------------ the trip

    public function pickUp(Rider $rider, Job $job): Job
    {
        $code = (string) random_int(1000, 9999);
        $this->riderTransition($rider, $job, ['assigned'], fn (Job $j) => $j->update(['status' => 'picked_up', 'picked_up_at' => now(), 'handover_code_hash' => $this->hash($j, $code), 'handover_code_enc' => \Illuminate\Support\Facades\Crypt::encryptString($code)]), 'Accept this delivery before picking it up.');
        $job->refresh();
        $this->sms($job, $job->dropoff_phone, "Your {$job->pickup_name} order is on its way with Zinnvy rider {$rider->name} ({$rider->phone}). You will pay GH₵ ".number_format($job->total() / 100, 2)." by Mobile Money when it arrives. Give the rider this code after paying: {$code}. Track it: {$job->trackUrl()}");

        return $job;
    }

    /** Creates the payment the customer pays (QR or link) and returns the page it opens. Safe to call again. */
    public function collectPayment(Rider $rider, Job $job): array
    {
        $this->ownedBy($rider, $job);
        if ($job->status !== 'picked_up') {
            throw ValidationException::withMessages(['job' => 'Pick up the order first.']);
        }
        if ($job->pay_status === 'paid') {
            return ['status' => 'paid', 'checkout_url' => null];
        }
        if (! $job->pay_intent_id) {
            $splits = array_values(array_filter([
                ['owner_ref' => $job->ownerWalletRef(), 'kind' => 'owner', 'amount' => $job->ownerShare(), 'label' => "Order #{$job->order->number}", 'hold_minutes' => (int) Settings::get('owner_hold_minutes')],
                ['owner_ref' => $rider->walletRef(), 'kind' => 'rider', 'amount' => $job->rider_share, 'label' => "Delivery {$job->code}", 'hold_minutes' => (int) Settings::get('rider_hold_minutes')],
            ], fn ($s) => $s['amount'] > 0));
            $this->pay->ensureWallet($job->ownerWalletRef(), 'owner', $job->pickup_name);
            $this->pay->ensureWallet($rider->walletRef(), 'rider', $rider->name);
            // A failed attempt is final in Pay, so each new attempt is its own payment with its own reference.
            $attempt = (int) ($job->rates['pay_attempts'] ?? 0) + 1;
            $reference = $attempt === 1 ? "job-{$job->code}" : "job-{$job->code}-{$attempt}";
            $intent = $this->pay->createPayment([
                'amount' => $job->total(), 'description' => "{$job->pickup_name}: order #{$job->order->number} + delivery", 'reference' => $reference,
                'product' => 'zinnvy-dispatch', 'splits' => $splits, 'metadata' => ['job' => $job->code, 'order' => $job->order->number],
            ], $reference);
            $job->update(['pay_intent_id' => $intent['id'], 'pay_checkout_url' => $intent['checkout_url'], 'pay_status' => 'pending', 'rates' => ($job->rates ?? []) + ['pay_attempts' => $attempt]]);
        }

        return ['status' => 'pending', 'checkout_url' => $job->pay_checkout_url];
    }

    /** Texts the customer a link to pay on their own phone, if they would rather not scan. */
    public function sendPayLink(Rider $rider, Job $job): void
    {
        $this->ownedBy($rider, $job);
        abort_unless($job->pay_checkout_url && $job->pay_status === 'pending', 422, 'Start the payment first.');
        $this->sms($job, $job->dropoff_phone, "Pay GH₵ ".number_format($job->total() / 100, 2)." for your {$job->pickup_name} order by Mobile Money: {$job->pay_checkout_url}");
    }

    /** Asks Pay whether the customer has paid. Called by the rider's app and by Pay's webhook. */
    public function refreshPayment(Job $job): Job
    {
        if ($job->pay_status === 'paid' || ! $job->pay_intent_id) {
            return $job;
        }
        $intent = $this->pay->getPayment($job->pay_intent_id);
        if (($intent['status'] ?? null) === 'succeeded') {
            $job->update(['pay_status' => 'paid', 'paid_at' => now()]);
        } elseif (in_array($intent['status'] ?? null, ['failed', 'expired', 'canceled'], true)) {
            // A failed attempt: let the rider start a fresh payment.
            $job->update(['pay_status' => 'unpaid', 'pay_intent_id' => null, 'pay_checkout_url' => null]);
        }

        return $job->refresh();
    }

    public function deliver(Rider $rider, Job $job, string $code): Job
    {
        $this->ownedBy($rider, $job);
        $key = "dispatch-code:{$job->id}";
        if ((int) Cache::get($key, 0) >= self::MAX_CODE_TRIES) {
            throw ValidationException::withMessages(['code' => 'Too many wrong codes. Contact Zinnvy support.']);
        }
        $job = $this->refreshPayment($job);
        if ($job->status !== 'picked_up') {
            throw ValidationException::withMessages(['job' => 'This delivery is not on the road.']);
        }
        if ($job->pay_status !== 'paid') {
            throw ValidationException::withMessages(['job' => 'The customer has not paid yet.']);
        }
        if (! hash_equals((string) $job->handover_code_hash, $this->hash($job, $code))) {
            Cache::put($key, (int) Cache::get($key, 0) + 1, now()->addHours(6));
            throw ValidationException::withMessages(['code' => 'That code is not right. Ask the customer to check their SMS.']);
        }

        DB::transaction(function () use ($job, $rider) {
            $job->update(['status' => 'delivered', 'delivered_at' => now(), 'closed_at' => now()]);
            $rider->increment('deliveries');
        });
        $this->closeOrder($job);

        return $job->refresh();
    }

    /** The customer could not or would not pay, or could not be found. Nothing was paid, so nothing is split. */
    public function fail(Rider $rider, Job $job, string $reason): Job
    {
        $this->ownedBy($rider, $job);
        $job = $this->refreshPayment($job);
        if ($job->pay_status === 'paid') {
            throw ValidationException::withMessages(['job' => 'The customer already paid: finish the delivery.']);
        }
        $this->transition($job, ['assigned', 'picked_up'], fn (Job $j) => $j->update(['status' => 'failed', 'failure_reason' => mb_substr($reason, 0, 190), 'closed_at' => now(), 'trip_fee_due' => $j->status === 'picked_up']), 'This delivery is already finished.');
        $job->refresh();
        if ($job->trip_fee_due) {
            $this->payTripFee($job);
        }
        $shop = Shop::withoutGlobalScopes()->find($job->order->shop_id);
        $this->sms($job, $shop?->notify_phone, "Delivery {$job->code} (order #{$job->order->number}) could not be completed: {$job->failure_reason}. Your goods are being returned.");

        return $job;
    }

    /** Pays the rider the failed-trip fee from Zinnvy's own money. Retried until Pay takes it. */
    public function payTripFee(Job $job): void
    {
        $fee = (int) Settings::get('failed_trip_fee');
        if (! $job->trip_fee_due || $fee < 1 || ! $job->rider) {
            return;
        }
        try {
            $this->pay->adjust($job->rider->walletRef(), $fee, "Failed trip {$job->code}", "tripfee-{$job->code}");
            $job->update(['trip_fee_due' => false]);
        } catch (\Throwable $e) {
            report($e);   // stays due: tick() retries
        }
    }

    public function retryTripFees(): void
    {
        Job::where('trip_fee_due', true)->where('status', 'failed')->get()->each(fn (Job $j) => $this->payTripFee($j));
    }

    // ------------------------------------------------------------------ helpers

    private function closeOrder(Job $job): void
    {
        $order = $job->order()->with('items')->first();
        if (! $order || $order->status === 'completed') {
            return;
        }
        // Stock and sales are scoped to the signed-in workspace; the rider belongs to Zinnvy Dispatch, so act as the shop.
        $owner = \App\Models\User::where('tenant_id', $job->tenant_id)->where('role', 'Administrator')->orderBy('id')->first();
        $previous = Auth::user();
        try {
            if ($owner) {
                Auth::setUser($owner);
            }
            $this->orders->convert($order, 'mobile_money');
        } catch (\Throwable $e) {
            // The customer has paid and the goods are delivered: never undo that because stock was short.
            Log::warning('Dispatch: order could not be turned into a sale', ['job' => $job->code, 'error' => $e->getMessage()]);
        } finally {
            if ($previous) {
                Auth::setUser($previous);
            }
        }
    }

    private function ownedBy(Rider $rider, Job $job): void
    {
        abort_unless($job->rider_id === $rider->id, 403, 'This is not your delivery.');
    }

    private function riderTransition(Rider $rider, Job $job, array $from, callable $apply, string $message): void
    {
        $this->ownedBy($rider, $job);
        $this->transition($job, $from, $apply, $message);
    }

    private function transition(Job $job, array $from, callable $apply, string $message): void
    {
        DB::transaction(function () use ($job, $from, $apply, $message) {
            $locked = Job::whereKey($job->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['job' => $message]);
            }
            $apply($locked);
        });
    }

    private function newCode(): string
    {
        do {
            $code = 'D-'.random_int(1000, 9999);
        } while (Job::where('code', $code)->exists());

        return $code;
    }

    private function hash(Job $job, string $code): string
    {
        return hash_hmac('sha256', $job->id.':'.preg_replace('/\D/', '', $code), (string) config('app.key'));
    }

    private function sms(Job $job, ?string $to, string $text): void
    {
        if (! $to) {
            return;
        }
        rescue(fn () => app(SmsService::class)->queueSms($job->tenant_id, 'Zinnvy', $to, $text), report: true);
    }
}
