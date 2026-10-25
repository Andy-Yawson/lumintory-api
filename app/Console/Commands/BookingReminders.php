<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\Shop\BookingService;
use Illuminate\Console\Command;

/** Hourly: text customers about confirmed bookings starting in the next 20 to 28 hours (once each). */
class BookingReminders extends Command
{
    protected $signature = 'bookings:remind';
    protected $description = 'SMS reminders for tomorrow\'s confirmed bookings';

    public function handle(BookingService $bookings): int
    {
        $due = Booking::withoutGlobalScopes()->where('status', 'confirmed')->whereNull('reminder_sent_at')
            ->whereBetween('starts_at', [now()->addHours(20), now()->addHours(28)])->get();
        foreach ($due as $b) {
            $bookings->notifyCustomer($b, 'reminder');
            $b->forceFill(['reminder_sent_at' => now()])->save();
        }
        $this->info("Sent {$due->count()} reminder(s).");

        return self::SUCCESS;
    }
}
