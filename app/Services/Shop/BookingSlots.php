<?php

namespace App\Services\Shop;

use App\Models\Booking;
use App\Models\Shop;
use App\Models\ShopService;
use Carbon\CarbonImmutable;

/**
 * Which start times are free for a service: inside the owner's weekly hours, not on a blocked
 * date, after the notice period, within the booking window, and not overlapping another booking
 * (plus the owner's buffer between appointments). Times are Ghana time (= UTC).
 */
class BookingSlots
{
    public const STEP_MINUTES = 30;
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    public const DEFAULT_HOURS = ['mon' => [['09:00', '18:00']], 'tue' => [['09:00', '18:00']], 'wed' => [['09:00', '18:00']], 'thu' => [['09:00', '18:00']], 'fri' => [['09:00', '18:00']], 'sat' => [['09:00', '16:00']], 'sun' => []];

    public static function hours(Shop $shop): array
    {
        return $shop->booking_hours ?: self::DEFAULT_HOURS;
    }

    /** @return array<int, string> "HH:MM" start times */
    public function forDate(Shop $shop, ShopService $service, string $date, ?int $ignoreBookingId = null): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        $now = CarbonImmutable::now();
        if ($day->lt($now->startOfDay()) || $day->gt($now->addDays($shop->booking_max_days ?: 30)->endOfDay())) {
            return [];
        }
        if (in_array($day->toDateString(), $shop->booking_blocked_dates ?? [], true)) {
            return [];
        }

        $intervals = self::hours($shop)[self::DAYS[$day->dayOfWeekIso - 1]] ?? [];
        $earliest = $now->addHours($shop->booking_notice_hours ?? 0);
        $buffer = (int) ($shop->booking_buffer_minutes ?? 0);
        $duration = max(15, (int) $service->duration_minutes);

        $taken = Booking::withoutGlobalScopes()->where('shop_id', $shop->id)->whereIn('status', Booking::HOLDS_SLOT)
            ->when($ignoreBookingId, fn ($q) => $q->whereKeyNot($ignoreBookingId))
            ->where('starts_at', '<', $day->endOfDay())->where('ends_at', '>', $day->startOfDay())
            ->get(['starts_at', 'ends_at'])
            ->map(fn ($b) => [CarbonImmutable::parse($b->starts_at)->subMinutes($buffer), CarbonImmutable::parse($b->ends_at)->addMinutes($buffer)]);

        $out = [];
        foreach ($intervals as [$open, $close]) {
            $t = $day->setTimeFromTimeString($open);
            $end = $day->setTimeFromTimeString($close);
            for (; $t->addMinutes($duration)->lte($end); $t = $t->addMinutes(self::STEP_MINUTES)) {
                $slotEnd = $t->addMinutes($duration);
                if ($t->lt($earliest)) {
                    continue;
                }
                if ($taken->contains(fn ($b) => $t->lt($b[1]) && $slotEnd->gt($b[0]))) {
                    continue;
                }
                $out[] = $t->format('H:i');
            }
        }

        return array_values(array_unique($out));
    }

    /** Dates in the booking window that have at least one free slot. */
    public function availableDates(Shop $shop, ShopService $service): array
    {
        $days = [];
        $today = CarbonImmutable::now()->startOfDay();
        for ($i = 0; $i <= ($shop->booking_max_days ?: 30); $i++) {
            $d = $today->addDays($i)->toDateString();
            if ($this->forDate($shop, $service, $d)) {
                $days[] = $d;
            }
        }

        return $days;
    }

    public function isFree(Shop $shop, ShopService $service, CarbonImmutable $start, ?int $ignoreBookingId = null): bool
    {
        return in_array($start->format('H:i'), $this->forDate($shop, $service, $start->toDateString(), $ignoreBookingId), true);
    }
}
