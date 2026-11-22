<?php

use App\Domain\Billing\Actions\ChangeSubscription;
use App\Domain\Billing\Actions\MarkInvoicePaid;
use App\Domain\Billing\Actions\RenewSubscription;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Jobs\ProcessSubscriptionLifecycle;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Notifications\SubscriptionRenewalReminder;
use App\Domain\Tenancy\Enums\TenantStatus;
use Illuminate\Support\Facades\Notification;

it('issues a renewal invoice and reminders, then grace, then suspension', function () {
    Notification::fake();
    $tenant = createTenant('essential');
    $sub = $tenant->currentSubscription;

    $this->travelTo($sub->ends_at->copy()->subDays(7)->addHour());
    (new ProcessSubscriptionLifecycle)->handle(app(RenewSubscription::class));
    expect($tenant->invoices()->where('type', 'renewal')->count())->toBe(1);
    Notification::assertSentTo($tenant->owner, SubscriptionRenewalReminder::class);

    $this->travelTo($sub->ends_at->copy()->addHour());
    (new ProcessSubscriptionLifecycle)->handle(app(RenewSubscription::class));
    expect($tenant->fresh()->status)->toBe(TenantStatus::Grace)->and($sub->fresh()->status)->toBe(SubscriptionStatus::Grace);

    // Grace = read-only dashboard
    $this->actingAs($tenant->owner)->get(tenantUrl($tenant, 'dashboard'))->assertOk()->assertSee(__('salon.billing.pay_now'));

    $this->travelTo($sub->ends_at->copy()->addDays(config('salon.grace_days') + 1));
    (new ProcessSubscriptionLifecycle)->handle(app(RenewSubscription::class));
    expect($tenant->fresh()->status)->toBe(TenantStatus::Suspended);

    // Public site shows a polite unavailable page; dashboard is locked.
    $this->get(tenantUrl($tenant))->assertStatus(503)->assertSee(__('salon.unavailable.title'));
    $this->actingAs($tenant->owner)->get(tenantUrl($tenant, 'dashboard'))->assertRedirect(route('tenant.locked'));

    // Paying the renewal reactivates and extends the cycle.
    app(MarkInvoicePaid::class)->handle($tenant->invoices()->where('type', 'renewal')->first());
    expect($tenant->fresh()->status)->toBe(TenantStatus::Active)->and($tenant->fresh()->hasModule('booking'))->toBeTrue();
});

it('prorates a mid-cycle upgrade and applies it only when paid', function () {
    $tenant = createTenant('essential');
    $sub = $tenant->currentSubscription;
    $this->travel(15)->days();

    $invoice = app(ChangeSubscription::class)->handle($sub, Plan::where('slug', 'essential')->first(), 3, ['pos']);
    expect($invoice)->not->toBeNull()->and($invoice->type)->toBe('proration')->and($invoice->total)->toBeGreaterThan(0)
        ->and($tenant->fresh()->hasModule('pos'))->toBeFalse();

    app(MarkInvoicePaid::class)->handle($invoice);
    expect($tenant->fresh()->hasModule('pos'))->toBeTrue()->and($sub->fresh()->branches_count)->toBe(3);
});

it('credits downgrades to the next renewal', function () {
    $tenant = createTenant('essential', 3, ['pos']);
    $sub = $tenant->currentSubscription;
    $this->travel(10)->days();

    expect(app(ChangeSubscription::class)->handle($sub, Plan::where('slug', 'essential')->first(), 1, []))->toBeNull();
    expect($sub->fresh()->price_snapshot['pending_credit'])->toBeGreaterThan(0)
        ->and($tenant->fresh()->hasModule('pos'))->toBeFalse();
});
