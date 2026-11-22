<?php

use Illuminate\Support\Facades\Schedule;

// Booking: 24h / 2h reminders + post-visit thank-you (idempotent via reminder_*_sent_at / review_requested_at).
Schedule::command('booking:reminders')->everyFiveMinutes()->withoutOverlapping()->onOneServer()->name('booking:reminders');

// Booking: expire unpaid online bookings, slot holds and waitlist offers.
Schedule::command('booking:housekeeping')->everyFiveMinutes()->withoutOverlapping()->onOneServer()->name('booking:housekeeping');
