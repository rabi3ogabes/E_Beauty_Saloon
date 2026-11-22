<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Billing\Actions\StartSubscription;
use App\Domain\Billing\Models\Plan;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Exceptions\DomainException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Creates a salon account end-to-end: tenant, owner user, subdomain, roles and (optionally) a subscription.
 * Used by /admin "New salon", the public self-serve signup and the demo seeder.
 */
class CreateTenant
{
    public function __construct(protected ProvisionTenantRoles $roles, protected StartSubscription $subscriptions) {}

    /**
     * @param  array{name:string, slug?:string, email?:string, phone?:string, owner_name:string, owner_email:string, owner_phone?:string, owner_password?:string|null, locale?:string, timezone?:string, currency?:string, country?:string}  $data
     * @param  array{plan_id:int, cycle_months:int, branches?:int, modules?:array, coupon?:string|null, trial?:bool}|null  $subscription
     */
    public function handle(array $data, ?array $subscription = null, bool $sendInvite = false): Tenant
    {
        $slug = Str::slug($data['slug'] ?? $data['name']);
        if (in_array($slug, ['admin', 'app', 'www', 'api', 'mail', 'static', 'cdn'], true)) {
            throw new DomainException(__('salon.errors.slug_reserved'));
        }
        if (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug .= '-'.Str::lower(Str::random(4));
        }
        if (User::where('email', $data['owner_email'])->exists()) {
            throw new DomainException(__('salon.errors.email_taken'));
        }

        $tenant = DB::transaction(function () use ($data, $slug, $subscription) {
            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $slug,
                'email' => $data['email'] ?? $data['owner_email'],
                'phone' => $data['phone'] ?? ($data['owner_phone'] ?? null),
                'status' => TenantStatus::Trial,
                'country' => $data['country'] ?? config('salon.defaults.country'),
                'currency' => $data['currency'] ?? config('salon.defaults.currency'),
                'timezone' => $data['timezone'] ?? config('salon.defaults.timezone'),
                'default_locale' => $data['locale'] ?? config('salon.defaults.locale'),
                'branding' => Tenant::DEFAULT_BRANDING,
                'settings' => [
                    'booking' => ['slot_interval' => 15, 'min_notice_minutes' => 60, 'max_days_ahead' => 60, 'cancellation_window_hours' => 24, 'late_cancel_fee_percent' => 0, 'no_show_fee_percent' => 100, 'reschedule_limit' => 2, 'hold_minutes' => 10],
                    'loyalty' => ['points_per_currency_unit' => 1, 'point_value' => 5],
                    'ai' => ['free_tries_per_client' => 3],
                ],
            ]);

            $owner = new User([
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'phone' => $data['owner_phone'] ?? null,
                'password' => $data['owner_password'] ?? Str::password(32),
                'locale' => $data['locale'] ?? 'en',
            ]);
            $owner->tenant_id = $tenant->id;
            $owner->save();
            $tenant->update(['owner_user_id' => $owner->id]);

            $tenant->domains()->create([
                'domain' => $slug.'.'.config('salon.base_domain'),
                'type' => 'subdomain',
                'is_primary' => true,
                'verified_at' => now(),
                'ssl_status' => 'active',
            ]);

            $this->roles->handle($tenant);
            app(TenantContext::class)->run($tenant, fn () => $owner->assignRole('owner'));

            if ($subscription) {
                $this->subscriptions->handle(
                    $tenant,
                    Plan::findOrFail($subscription['plan_id']),
                    (int) $subscription['cycle_months'],
                    (int) ($subscription['branches'] ?? 1),
                    $subscription['modules'] ?? [],
                    $subscription['coupon'] ?? null,
                    (bool) ($subscription['trial'] ?? false),
                );
            }

            activity()->performedOn($tenant)->log('tenant.created');

            return $tenant;
        });

        if ($sendInvite) {
            Password::broker()->sendResetLink(['email' => $data['owner_email']]);
        }

        return $tenant->fresh(['owner', 'domains']);
    }
}
