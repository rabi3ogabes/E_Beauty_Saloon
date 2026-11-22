<?php

use App\Domain\Shop\Jobs\DetectAbandonedCarts;
use App\Domain\Shop\Jobs\ReleaseExpiredReservations;
use Illuminate\Support\Facades\Schedule;

// Shop: release stock held by unpaid / failed online orders.
Schedule::job(new ReleaseExpiredReservations)->everyFiveMinutes()->onOneServer()->name('shop:release-reservations');

// Shop: abandoned carts (idle > 2h with a phone / client) → AbandonedCartDetected for marketing automations.
Schedule::job(new DetectAbandonedCarts)->everyFifteenMinutes()->onOneServer()->name('shop:abandoned-carts');
