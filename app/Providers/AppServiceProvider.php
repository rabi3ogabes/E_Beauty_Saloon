<?php

namespace App\Providers;

use App\Domain\AIStudio\Contracts\GeneratesMarketingContent;
use App\Domain\AIStudio\NullMarketingContentGenerator;
use App\Domain\CRM\Models\Client;
use App\Domain\Tenancy\Domains\CaddyOnDemandProvisioner;
use App\Domain\Tenancy\Domains\CloudflareSaasProvisioner;
use App\Domain\Tenancy\Domains\DomainProvisioner;
use App\Domain\Tenancy\Domains\NullProvisioner;
use App\Domain\Tenancy\Models\Tenant;
use App\Listeners\RecordLogin;
use App\Models\User;
use App\Support\Account\AccountSections;
use App\Support\Auth\PasswordLinks;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(AccountSections::class);

        // Each domain may ship its own provider: app/Domain/{Domain}/{Domain}ServiceProvider.php
        // (relations onto core models via resolveRelationUsing, listeners, bindings...).
        foreach (glob(app_path('Domain/*/*ServiceProvider.php')) as $file) {
            $class = 'App\\Domain\\'.basename(dirname($file)).'\\'.basename($file, '.php');
            $this->app->register($class);
        }

        $this->app->bindIf(GeneratesMarketingContent::class, NullMarketingContentGenerator::class);

        $this->app->bind(DomainProvisioner::class, fn () => match (config('salon.domains.provisioner')) {
            'caddy' => new CaddyOnDemandProvisioner,
            'cloudflare' => new CloudflareSaasProvisioner,
            default => new NullProvisioner,
        });
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction() && ! $this->app->runningUnitTests());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        PasswordLinks::register();

        // Module entitlements: module:{key} resolved from the tenant's active subscription.
        foreach (array_keys(config('salon.modules')) as $key) {
            Feature::define('module:'.$key, fn (Tenant $tenant) => $tenant->hasModule($key));
        }
        Feature::resolveScopeUsing(fn () => tenant());

        // Owners and the platform owner can do everything in their own scope.
        Gate::before(function (User $user, string $ability) {
            if ($user->is_platform && $user->hasRole('platform_owner')) {
                return true;
            }
            if (! $user->is_platform && $user->tenant_id === tenant()?->id && $user->isOwner() && ! str_starts_with($ability, 'platform.')) {
                return true;
            }

            return null;
        });

        Event::listen(Login::class, RecordLogin::class);

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by(($r->user()?->getAuthIdentifier() ?? $r->ip()).'|'.tenant()?->id));
        RateLimiter::for('otp', function (Request $r) {
            // Key on the normalised phone + salon so "5555 1234" / "+97455551234" / "0097455551234" share one bucket.
            $phone = tenant()?->id.':'.Client::normalizePhone((string) $r->input('phone'));

            return [
                Limit::perMinute(3)->by('otp:'.$phone),
                Limit::perDay(10)->by('otp-day:'.$phone),
                Limit::perHour(20)->by('otp-ip:'.$r->ip()),
            ];
        });
        RateLimiter::for('booking', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
    }
}
