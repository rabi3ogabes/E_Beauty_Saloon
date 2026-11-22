<?php

use App\Domain\Billing\Jobs\ProcessSubscriptionLifecycle;
use App\Domain\Tenancy\Jobs\VerifyCustomDomain;
use App\Domain\Tenancy\Models\Domain;
use Illuminate\Support\Facades\Schedule;

// Billing: reminders (7/3/1 days), renewal invoices, grace, suspension.
Schedule::job(new ProcessSubscriptionLifecycle)->dailyAt('06:00')->onOneServer()->name('billing:lifecycle');

// Custom domains: re-check unverified (or SSL pending) domains every 10 minutes.
Schedule::call(function () {
    Domain::query()->where('type', 'custom')
        ->where(fn ($q) => $q->whereNull('verified_at')->orWhere('ssl_status', '!=', 'active'))
        ->where('created_at', '>', now()->subDays(14))
        ->pluck('id')->each(fn ($id) => VerifyCustomDomain::dispatch($id));
})->everyTenMinutes()->name('domains:verify')->onOneServer();

// Each module may register its own schedule in routes/schedules/*.php
foreach (glob(base_path('routes/schedules/*.php')) as $file) {
    require $file;
}
