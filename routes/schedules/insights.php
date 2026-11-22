<?php

use App\Domain\Insights\Actions\SendMorningReports;
use Illuminate\Support\Facades\Schedule;

// Opt-in morning report at 08:00 branch-local time (every 15 min; once per branch per local day).
Schedule::call(fn () => app(SendMorningReports::class)->handle())
    ->everyFifteenMinutes()->name('insights:morning-report')->withoutOverlapping()->onOneServer();
