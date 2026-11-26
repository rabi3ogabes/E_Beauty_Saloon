<?php

use App\Domain\AIStudio\Actions\InstallExampleLooks;
use App\Domain\Billing\Jobs\ProcessSubscriptionLifecycle;
use App\Domain\Branches\Models\Branch;
use App\Domain\Tenancy\Jobs\VerifyCustomDomain;
use App\Domain\Tenancy\Models\Domain;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Site\AtelierController;
use App\Support\Monitoring\ErrorAlertMail;
use App\Support\Monitoring\ErrorContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

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

// Hosted demo: attach DEMO_TENANT_DOMAIN (e.g. lumiere.athardesign.qa) to the demo salon as its verified primary domain.
Artisan::command('app:sync-demo-domain', function () {
    $host = strtolower(trim((string) env('DEMO_TENANT_DOMAIN', '')));
    $tenant = Tenant::where('slug', env('DEMO_TENANT_SLUG', 'lumiere'))->first();
    if ($host === '' || ! $tenant) {
        $this->info('No demo domain to sync.');

        return;
    }
    $other = Domain::where('domain', $host)->where('tenant_id', '!=', $tenant->id)->exists();
    if ($other) {
        $this->error("{$host} belongs to another salon — not changed.");

        return;
    }
    $tenant->domains()->update(['is_primary' => false]);
    $tenant->domains()->updateOrCreate(['domain' => $host], [
        'type' => 'custom', 'is_primary' => true, 'verified_at' => now(), 'ssl_status' => 'active',
    ]);
    Cache::forget('tenant-domain:'.$host);
    $this->info("{$host} → {$tenant->name}");
})->purpose('Attach the hosted demo domain to the demo salon');

// Send a sample error alert to ERROR_ALERT_EMAIL to check mail delivery end to end.
Artisan::command('monitoring:test-alert', function () {
    $to = config('monitoring.alert_to');
    if ($to === []) {
        $this->error('Set ERROR_ALERT_EMAIL first.');

        return 1;
    }
    Mail::to($to)->send(new ErrorAlertMail(
        ErrorContext::from(new RuntimeException('Test alert — error monitoring is working.'))
    ));
    $this->info('Test alert sent to '.implode(', ', $to).' via the "'.config('mail.default').'" mailer.');
})->purpose('Send a test error-alert e-mail');

// Bundled example looks (nails, pedicure, hair extensions) with photos — for the demo salon or any salon by slug.
Artisan::command('studio:install-examples {--tenant= : salon slug (default: the demo salon)} {--force : replace existing photos too}', function () {
    $slug = $this->option('tenant') ?: env('DEMO_TENANT_SLUG', 'lumiere');
    $tenant = Tenant::where('slug', $slug)->first();
    if (! $tenant) {
        $this->warn("No salon '{$slug}' — nothing to install.");

        return;
    }
    $stats = app(InstallExampleLooks::class)->handle($tenant, (bool) $this->option('force'));
    $this->info("{$tenant->name}: {$stats['created']} looks added, {$stats['photographed']} given photos, {$stats['services']} services added.");
})->purpose('Install the bundled example looks and photos into a salon');

// Live Atelier: self-host the on-device face/hand tracking runtime and models (public disk, kept across deploys).
// Idempotent and never fails a deploy: without them the Atelier loads the same files from Google's and jsDelivr's CDNs.
Artisan::command('studio:install-atelier {--force : download again}', function () {
    $version = AtelierController::MEDIAPIPE_VERSION;
    $dir = 'vendor/mediapipe/'.$version;
    $files = [
        'face_landmarker.task' => 'https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task',
        'hand_landmarker.task' => 'https://storage.googleapis.com/mediapipe-models/hand_landmarker/hand_landmarker/float16/1/hand_landmarker.task',
        'wasm/vision_wasm_internal.js' => "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@{$version}/wasm/vision_wasm_internal.js",
        'wasm/vision_wasm_internal.wasm' => "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@{$version}/wasm/vision_wasm_internal.wasm",
    ];
    $disk = Storage::disk('public');
    foreach ($files as $name => $url) {
        $path = $dir.'/'.$name;
        if ($disk->exists($path) && $disk->size($path) > 1000 && ! $this->option('force')) {
            $this->line("✓ {$name}");

            continue;
        }
        try {
            $res = Http::timeout(120)->retry(2, 1000)->get($url);
            if (! $res->successful() || strlen($res->body()) < 1000) {
                $this->warn("Could not download {$name} (HTTP {$res->status()}); the Atelier will use the CDN.");

                continue;
            }
            $disk->put($path, $res->body());
            $this->info("↓ {$name} (".number_format(strlen($res->body()) / 1048576, 1).' MB)');
        } catch (Throwable $e) {
            $this->warn("Could not download {$name}: {$e->getMessage()}; the Atelier will use the CDN.");
        }
    }
})->purpose('Self-host the Live Atelier face/hand tracking models');
