<?php

use App\Domain\AIStudio\Actions\GrantMonthlyCredits;
use App\Domain\AIStudio\Actions\PurgeExpiredLooks;
use Illuminate\Support\Facades\Schedule;

// Privacy: delete unsaved try-on photos after the retention window.
Schedule::call(fn () => app(PurgeExpiredLooks::class)->handle())->dailyAt('03:20')->name('ai-studio:purge-looks')->withoutOverlapping();

// Monthly included credits from the plan (idempotent — safe to run daily).
Schedule::call(fn () => app(GrantMonthlyCredits::class)->handle())->dailyAt('00:35')->name('ai-studio:monthly-credits')->withoutOverlapping();
