<?php

use App\Domain\Insights\Actions\SendMorningReports;
use App\Domain\Insights\Jobs\GenerateTenantInsights;
use Illuminate\Support\Facades\Schedule;

// Nightly AI insight cards: one queued job per tenant (idempotent per tenant per local day).
Schedule::call(fn () => GenerateTenantInsights::dispatchForAllTenants())
    ->dailyAt('01:50')->name('insights:nightly')->withoutOverlapping()->onOneServer();

// Opt-in morning report at 08:00 branch-local time (every 15 min; once per branch per local day).
Schedule::call(fn () => app(SendMorningReports::class)->handle())
    ->everyFifteenMinutes()->name('insights:morning-report')->withoutOverlapping()->onOneServer();
