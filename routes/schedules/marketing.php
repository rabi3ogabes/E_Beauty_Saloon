<?php

use App\Domain\Marketing\Services\MarketingScheduler;
use Illuminate\Support\Facades\Schedule;

// Marketing: scheduled campaigns, quiet-hours deferrals, A/B winners, automation waits (idempotent).
Schedule::call(fn () => app(MarketingScheduler::class)->frequent())
    ->everyFiveMinutes()->name('marketing:frequent')->withoutOverlapping()->onOneServer();

// Marketing: birthday / win-back / rebook / wallet / package & membership triggers, expiries (idempotent per day).
Schedule::call(fn () => app(MarketingScheduler::class)->daily())
    ->dailyAt('07:40')->name('marketing:daily')->withoutOverlapping()->onOneServer();
