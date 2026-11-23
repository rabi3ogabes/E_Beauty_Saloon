<?php

use App\Domain\Billing\Jobs\ProcessSubscriptionLifecycle;
use App\Domain\Branches\Models\Branch;
use App\Domain\Tenancy\Jobs\VerifyCustomDomain;
use App\Domain\Tenancy\Models\Domain;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

// Backups: daily DB + media, 30-day retention (config/backup.php). Restore drill documented in docs/RUNBOOK.md.
Schedule::command('backup:clean')->dailyAt('01:30')->onOneServer();
Schedule::command('backup:run')->dailyAt('02:00')->onOneServer();
Schedule::command('backup:monitor')->dailyAt('08:00')->onOneServer();

// First-install seeding for hosted deploys: seeds the platform catalog + demo salon only once.
Artisan::command('app:seed-once', function () {
    if (blank(config('app.key'))) {
        // Hosts that write APP_KEY when a release goes live: seed on the next deploy instead of failing this one.
        $this->warn('No APP_KEY yet — seeding will run on the next deploy.');

        return;
    }
    if (Branch::withoutGlobalScopes()->exists()) {
        $this->info('Already seeded — skipping.');

        return;
    }
    // One transaction: a failure leaves nothing half-seeded, so the next deploy simply tries again.
    DB::transaction(fn () => $this->call('db:seed', ['--force' => true]));
})->purpose('Seed the database on the first deploy only');
