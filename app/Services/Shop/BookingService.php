<?php

namespace App\Services\Shop;

use App\Helpers\MailHelper;
use App\Models\Booking;
use App\Models\Shop;
use App\Models\ShopService;
use App\Models\User;
use App\Services\SmsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Booking requests from the shop and the owner's confirm / decline / reschedule, with SMS + email. */
class BookingService
{
    public const MAX_REQUESTS_PER_PHONE_PER_DAY = 3;

    public function __construct(private BookingSlots $slots) {}

    public function request(Shop $shop, ShopService $service, array $data): Booking
    {
        $start = CarbonImmutable::parse($data['starts_at']);
        $phone = ShopOrderService::normalisePhone($data['phone']);
        $location = $data['location'] ?? ($service->location === 'home' ? 'home' : 'shop');

        if ($service->location !== 'both' && $location !== $service->location) {
            throw ValidationException::withMessages(['location' => $service->location === 'home' ? 'This service is done at your home.' : 'This service is done at the shop.']);
        }
        if ($location === 'home' && trim((string) ($data['address'] ?? '')) === '') {
            throw ValidationException::withMessages(['address' => 'Add the address where the service should happen.']);
        }
        if ($shop->isBlocked($phone)) {
            throw ValidationException::withMessages(['phone' => 'This shop isn\'t accepting bookings from this number.']);
        }
        $recent = Booking::withoutGlobalScopes()->where('shop_id', $shop->id)->where('customer_phone', $phone)->where('created_at', '>', now()->subDay())->count();
        if ($recent >= self::MAX_REQUESTS_PER_PHONE_PER_DAY) {
            throw ValidationException::withMessages(['phone' => 'You\'ve already requested several bookings today. The shop will be in touch.']);
        }

        $booking = DB::transaction(function () use ($shop, $service, $data, $start, $phone, $location) {
            // Lock this shop's bookings so two people can't take the same slot at once.
            $last = Booking::withoutGlobalScopes()->where('tenant_id', $shop->tenant_id)->lockForUpdate()->max('number');
            if (! $this->slots->isFree($shop, $service, $start)) {
                throw ValidationException::withMessages(['starts_at' => 'Sorry, that time was just taken. Please pick another.']);
            }
            $b = new Booking([
                'shop_id' => $shop->id, 'service_id' => $service->id, 'number' => max(1000, (int) $last) + 1, 'status' => 'requested',
                'service_name' => $service->name, 'price' => $service->price, 'duration_minutes' => $service->duration_minutes,
                'starts_at' => $start, 'ends_at' => $start->addMinutes($service->duration_minutes), 'location' => $location,
                'address' => $location === 'home' ? trim((string) $data['address']) : null,
                'for_product' => isset($data['for_product']) ? mb_substr(trim($data['for_product']), 0, 160) ?: null : null,
                'customer_name' => trim($data['name']), 'customer_phone' => $phone, 'customer_email' => $data['email'] ?? null,
                'note' => isset($data['note']) ? trim($data['note']) ?: null : null,
                'source' => ShopOrderService::cleanSource($data['source'] ?? null),
                'visitor_id' => isset($data['visitor']) ? substr(preg_replace('/[^A-Za-z0-9-]/', '', (string) $data['visitor']), 0, 40) ?: null : null,
            ]);
            $b->tenant_id = $shop->tenant_id;
            $b->save();

            return $b;
        });

        $this->notifyRequested($shop, $booking);

        return $booking;
    }

    public function setStatus(Booking $booking, string $status, ?string $note = null): Booking
    {
        if (in_array($booking->status, ['completed', 'cancelled', 'declined', 'no_show'], true) && $status !== 'confirmed') {
            throw ValidationException::withMessages(['status' => 'This booking is already '.str_replace('_', ' ', $booking->status).'.']);
        }
        if ($status === 'confirmed' && ! in_array($booking->status, Booking::HOLDS_SLOT, true)) {
            // Re-confirming a declined/cancelled booking: the time must still be free.
            $service = ShopService::withoutGlobalScopes()->find($booking->service_id);
            if ($service && ! $this->slots->isFree($booking->shop, $service, CarbonImmutable::parse($booking->starts_at), $booking->id)) {
                throw ValidationException::withMessages(['status' => 'That time is no longer free. Reschedule it instead.']);
            }
        }
        $booking->status = $status;
        $booking->owner_note = $note ?: $booking->owner_note;
        if ($status === 'confirmed') {
            $booking->confirmed_at = now();
        }
        $booking->save();

        $this->notifyCustomer($booking, match ($status) {
            'confirmed' => 'confirmed',
            'declined' => 'declined',
            'cancelled' => 'cancelled',
            default => null,
        });

        return $booking;
    }

    /** The owner moves a booking. Owners may choose any time (they know their day); it's confirmed. */
    public function reschedule(Booking $booking, CarbonImmutable $start, ?string $note = null): Booking
    {
        $clash = Booking::withoutGlobalScopes()->where('shop_id', $booking->shop_id)->whereKeyNot($booking->id)->whereIn('status', Booking::HOLDS_SLOT)
            ->where('starts_at', '<', $start->addMinutes($booking->duration_minutes))->where('ends_at', '>', $start)->first();
        if ($clash) {
            throw ValidationException::withMessages(['starts_at' => "That overlaps booking B-{$clash->number} ({$clash->customer_name}, ".CarbonImmutable::parse($clash->starts_at)->format('D j M, g:ia').').']);
        }
        $booking->fill(['starts_at' => $start, 'ends_at' => $start->addMinutes($booking->duration_minutes), 'status' => 'confirmed', 'confirmed_at' => now(), 'reminder_sent_at' => null]);
        $booking->owner_note = $note ?: $booking->owner_note;
        $booking->save();
        $this->notifyCustomer($booking, 'rescheduled');

        return $booking;
    }

    // ---------------------------------------------------------------- messages

    public static function when(Booking $b): string
    {
        return CarbonImmutable::parse($b->starts_at)->format('D j M, g:ia');
    }

    private function notifyRequested(Shop $shop, Booking $b): void
    {
        $tenant = $shop->tenant;
        $link = rtrim((string) config('services.frontend_url'), '/').'/dashboard/bookings/?booking='.$b->id;
        $where = $b->location === 'home' ? "At the customer's home: {$b->address}" : 'At your shop';
        $body = "New booking request B-{$b->number}: {$b->service_name}\n\nWhen: ".self::when($b)." ({$b->duration_minutes} min)\n{$where}\n"
            ."Customer: {$b->customer_name}, {$b->customer_phone}\n"
            .($b->for_product ? "For: {$b->for_product}\n" : '').($b->note ? "Note: {$b->note}\n" : '')
            ."\nConfirm or decline it here: {$link}\nThe customer gets a text as soon as you do.\n\nZinnvy.";
        rescue(fn () => User::where('tenant_id', $tenant->id)->where('role', 'Administrator')->pluck('email')
            ->each(fn ($e) => MailHelper::sendEmailNotification($e, "Booking request: {$b->service_name}, ".self::when($b), $body)), report: true);

        $sms = app(SmsService::class);
        if ($to = $shop->notify_phone ?: $shop->whatsapp) {
            rescue(fn () => $sms->queueSms($tenant->id, 'Zinnvy', $to, "Booking request B-{$b->number}: {$b->service_name}, ".self::when($b)." from {$b->customer_name} {$b->customer_phone}. Confirm in Zinnvy Inventory > Bookings."), report: true);
        }
        rescue(fn () => $sms->queueSms($tenant->id, 'Zinnvy', $b->customer_phone,
            "Hi {$b->customer_name}, {$tenant->name} got your booking request for {$b->service_name} on ".self::when($b).". You'll get a text when they confirm. Ref B-{$b->number}"), report: true);
    }

    public function notifyCustomer(Booking $b, ?string $what): void
    {
        if (! $what) {
            return;
        }
        $shop = $b->shop()->with('tenant')->first();
        $name = $shop->tenant->name;
        $momo = $shop->momo();
        $text = match ($what) {
            'confirmed' => "Confirmed! {$name} will see you for {$b->service_name} on ".self::when($b).($b->location === 'home' ? ' at your address.' : '.')
                .($momo && ! empty($momo['number']) ? ' Deposit (if agreed): '.($momo['network'] ?? '')." MoMo {$momo['number']}, ref B-{$b->number}." : " Ref B-{$b->number}"),
            'rescheduled' => "{$name} moved your {$b->service_name} booking to ".self::when($b).'.'.($b->owner_note ? " Note: {$b->owner_note}" : '')." Ref B-{$b->number}",
            'declined' => "Sorry, {$name} can't do {$b->service_name} on ".self::when($b).'.'.($b->owner_note ? " {$b->owner_note}" : ' Please book another time.'),
            'cancelled' => "Your {$b->service_name} booking with {$name} on ".self::when($b)." has been cancelled. Ref B-{$b->number}",
            'reminder' => "Reminder: {$b->service_name} with {$name} tomorrow, ".CarbonImmutable::parse($b->starts_at)->format('g:ia').($b->location === 'home' ? ' at your address.' : '.').($shop->whatsapp ? " Questions: {$shop->whatsapp}" : ''),
        };
        rescue(fn () => app(SmsService::class)->queueSms($b->tenant_id, 'Zinnvy', $b->customer_phone, $text), report: true);
        if ($b->customer_email) {
            rescue(fn () => MailHelper::sendEmailNotification($b->customer_email, "Your booking with {$name}", "Hi {$b->customer_name},\n\n{$text}\n\nPowered by Zinnvy."), report: true);
        }
    }
}
