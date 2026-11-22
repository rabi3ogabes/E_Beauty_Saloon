<?php

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Plan;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Exceptions\DomainException;

it('creates a tenant with 3 branches + POS + Booking for 6 months and a correct invoice', function () {
    $plan = Plan::where('slug', 'essential')->first();

    $tenant = app(CreateTenant::class)->handle([
        'name' => 'Rose Atelier', 'owner_name' => 'Hessa', 'owner_email' => 'hessa@example.com', 'owner_password' => 'password-1234',
    ], ['plan_id' => $plan->id, 'cycle_months' => 6, 'branches' => 3, 'modules' => ['pos', 'booking']]);

    $invoice = $tenant->invoices()->first();
    $expectedSubtotal = 29900 * 6 + 2 * 14900 * 6 + 9900 * 3 * 6;

    expect($tenant->slug)->toBe('rose-atelier')
        ->and($tenant->primaryDomain->domain)->toBe('rose-atelier.salon.test')
        ->and($tenant->owner->hasRole('owner'))->toBeFalse() // team id not set outside tenant context
        ->and($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->number)->toMatch('/^INV-\d{4}-\d{6}$/')
        ->and($invoice->subtotal)->toBe($expectedSubtotal)
        ->and($invoice->total)->toBe($expectedSubtotal - (int) round($expectedSubtotal * 0.10))
        ->and($tenant->subscriptions()->first()->status)->toBe(SubscriptionStatus::Pending)
        ->and($tenant->hasModule('pos'))->toBeFalse(); // not until paid

    asTenant($tenant, fn () => expect($tenant->owner->fresh()->hasRole('owner'))->toBeTrue());
});

it('activates the subscription and entitles modules once the invoice is paid', function () {
    $tenant = createTenant('essential', 1, ['pos']);

    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->currentSubscription->status)->toBe(SubscriptionStatus::Active)
        ->and($tenant->hasModule('pos'))->toBeTrue()
        ->and($tenant->hasModule('shop'))->toBeFalse()
        ->and($tenant->currentSubscription->ends_at->isSameDay(now()->addMonth()))->toBeTrue();
});

it('numbers invoices sequentially per year', function () {
    createTenant();
    createTenant();
    $numbers = Invoice::orderBy('id')->pluck('number')->all();

    expect($numbers[0])->toEndWith('000001')->and($numbers[1])->toEndWith('000002');
});

it('makes a trial tenant usable immediately without payment', function () {
    $plan = Plan::where('slug', 'signature')->first();
    $tenant = app(CreateTenant::class)->handle(['name' => 'Trial Salon', 'owner_name' => 'A', 'owner_email' => 'trial@example.com'],
        ['plan_id' => $plan->id, 'cycle_months' => 1, 'trial' => true]);

    expect($tenant->status)->toBe(TenantStatus::Trial)
        ->and($tenant->fresh()->hasModule('pos'))->toBeTrue()
        ->and($tenant->trial_ends_at->isSameDay(now()->addDays(14)))->toBeTrue();
});

it('rejects reserved slugs and duplicate owner emails', function () {
    $plan = Plan::first();
    expect(fn () => app(CreateTenant::class)->handle(['name' => 'admin', 'owner_name' => 'x', 'owner_email' => 'x@example.com']))
        ->toThrow(DomainException::class);

    createTenant(slug: 'one');
    expect(fn () => app(CreateTenant::class)->handle(['name' => 'Two', 'owner_name' => 'x', 'owner_email' => 'one@example.com']))
        ->toThrow(DomainException::class);
});
