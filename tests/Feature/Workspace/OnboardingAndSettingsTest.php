<?php

require_once __DIR__.'/WorkspaceHelpers.php';

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\PlatformPayment;
use App\Domain\Catalog\Models\Service;
use App\Domain\Payments\Gateways\GatewayManager;
use App\Domain\Payments\Models\TenantPaymentGateway;
use App\Domain\Tenancy\Domains\DnsResolver;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Jobs\VerifyCustomDomain;
use App\Domain\Tenancy\Models\ImpersonationLog;
use App\Domain\Workspace\Actions\CompleteOnboarding;
use App\Domain\Workspace\Actions\PreviewPlanChange;
use App\Domain\Workspace\Actions\RequestPlanChange;
use App\Domain\Workspace\Actions\StartPlatformCheckout;
use App\Domain\Workspace\Actions\SubmitBankTransferProof;
use App\Domain\Workspace\Actions\UpdateBusinessSettings;
use App\Filament\Dashboard\Pages\Billing;
use App\Filament\Dashboard\Pages\BusinessSettings;
use App\Filament\Dashboard\Pages\Domains;
use App\Filament\Dashboard\Pages\Onboarding;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('sends a first-time owner from the dashboard to the onboarding wizard', function () {
    $tenant = createTenant();
    $this->actingAs($tenant->owner)->get(tenantUrl($tenant, 'dashboard'))->assertOk()->assertSee('welcome', false);

    $tenant->forceFill(['onboarded_at' => now()])->save();
    $this->actingAs($tenant->owner)->get(tenantUrl($tenant, 'dashboard'))->assertOk()->assertDontSee('welcome', false);
});

it('onboarding wizard completes', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);

    Livewire::test(Onboarding::class)
        ->fillForm([
            'business' => ['name' => 'Maison Noor', 'phone' => '+97444001122', 'default_locale' => 'ar'],
            'branch' => ['name' => ['en' => 'West Bay', 'ar' => 'الخليج الغربي'], 'city' => 'Doha', 'lat' => 25.3214, 'lng' => 51.5310],
            'primary' => '#7A1C34', 'accent' => '#E9D6CF', 'font_display' => 'Cormorant Garamond', 'font_body' => 'Manrope', 'button_style' => 'rounded',
            'templates' => ['hair', 'nails'],
            'gateway' => ['driver' => 'stripe', 'mode' => 'test', 'credentials' => ['secret_key' => 'sk_test_onboard']],
        ])
        ->call('complete')
        ->assertHasNoFormErrors()
        ->assertSet('completed', true)
        ->assertSee(__('workspace.onboarding.done_title'));

    $tenant->refresh();
    expect($tenant->onboarded_at)->not->toBeNull()
        ->and($tenant->name)->toBe('Maison Noor')->and($tenant->default_locale)->toBe('ar')
        ->and($tenant->brand('primary'))->toBe('#7A1C34')->and($tenant->brand('button_style'))->toBe('rounded')
        ->and(ws_main_branch()->getTranslation('name', 'en'))->toBe('West Bay')
        ->and(ws_main_branch()->lat)->toBe(25.3214)
        ->and(Service::count())->toBeGreaterThan(10)
        ->and(TenantPaymentGateway::first()->credential('secret_key'))->toBe('sk_test_onboard');
});

it('complete onboarding action works without optional steps', function () {
    $tenant = createTenant();
    asTenant($tenant);
    app(CompleteOnboarding::class)->handle($tenant, ['business' => ['name' => 'Velvet']]);
    expect($tenant->fresh()->onboarded_at)->not->toBeNull()->and(TenantPaymentGateway::count())->toBe(0);
});

it('billing proof upload creates awaiting_approval payment', function () {
    Storage::fake('local');
    $tenant = createTenant('essential', 1);
    ws_owner_session($this, $tenant);
    $invoice = app(RequestPlanChange::class)->handle($tenant, branches: 2);
    expect($invoice->status)->toBe(InvoiceStatus::Open);

    Livewire::test(Billing::class)
        ->callTableAction('uploadProof', $invoice, data: ['proof' => UploadedFile::fake()->create('transfer.pdf', 120, 'application/pdf'), 'reference' => 'QNB-998877'])
        ->assertHasNoTableActionErrors();

    $payment = PlatformPayment::where('invoice_id', $invoice->id)->firstOrFail();
    expect($payment->status)->toBe('awaiting_approval')
        ->and($payment->gateway)->toBe('bank_transfer')
        ->and($payment->amount)->toBe($invoice->total)
        ->and($payment->reference)->toBe('QNB-998877');
    Storage::disk('local')->assertExists($payment->proof_path);
    expect(str_starts_with($payment->proof_path, 'billing-proofs/'.$tenant->id))->toBeTrue();

    // A second proof for the same invoice is refused while one is pending.
    expect(fn () => app(SubmitBankTransferProof::class)->handle($invoice->fresh(), UploadedFile::fake()->create('again.pdf', 10)))->toThrow(DomainException::class);
});

it('previews and requests a plan change with proration from the billing page', function () {
    $tenant = createTenant('essential', 1);
    ws_owner_session($this, $tenant);
    $signature = Plan::where('slug', 'signature')->first();

    $preview = app(PreviewPlanChange::class)->handle($tenant, $signature->id, 2, []);
    expect($preview['prorated'])->toBeGreaterThan(0)->and($preview['modules'])->toBe([]);

    Livewire::test(Billing::class)
        ->callAction('changePlan', data: ['plan_id' => $signature->id, 'branches' => 2, 'modules' => []])
        ->assertHasNoActionErrors();

    expect($tenant->invoices()->where('type', 'proration')->count())->toBe(1);
});

it('serves the billing page and invoice PDFs under tenant.billing routes, even when locked', function () {
    $tenant = createTenant();
    expect(route('tenant.billing.show', [], false))->toBe('/dashboard/billing');
    $invoice = $tenant->invoices()->first();

    $tenant->update(['status' => TenantStatus::Suspended]);
    $this->actingAs($tenant->owner)->get(tenantUrl($tenant, 'dashboard/billing'))->assertOk()->assertSee($invoice->number);
    $this->actingAs($tenant->owner)->get(tenantUrl($tenant, 'dashboard/branches'))->assertRedirect();
    $this->actingAs($tenant->owner)->get(tenantUrl($tenant, 'dashboard/billing/invoices/'.$invoice->id.'/pdf'))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('refuses another salon invoice PDF', function () {
    $a = createTenant();
    $b = createTenant();
    $this->actingAs($a->owner)->get(tenantUrl($a, 'dashboard/billing/invoices/'.$b->invoices()->first()->id.'/pdf'))->assertForbidden();
});

it('starts a platform checkout only when a platform gateway is configured', function () {
    $tenant = createTenant('essential', 1);
    asTenant($tenant);
    $invoice = app(RequestPlanChange::class)->handle($tenant, branches: 2);

    expect(StartPlatformCheckout::availableDrivers())->toBe([]);
    expect(fn () => app(StartPlatformCheckout::class)->handle($invoice, 'stripe'))->toThrow(DomainException::class);

    config(['services.platform_gateways.stripe' => ['secret_key' => 'sk_platform']]);
    GatewayManager::fake();
    $url = app(StartPlatformCheckout::class)->handle($invoice, 'stripe');
    expect($url)->not->toBeEmpty()
        ->and(PlatformPayment::where('invoice_id', $invoice->id)->where('status', 'pending')->exists())->toBeTrue();
    GatewayManager::fake(false);
});

it('saves booking policies and loyalty settings with sane bounds', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);

    Livewire::test(BusinessSettings::class)
        ->fillForm(['booking.slot_interval' => 30, 'booking.no_show_fee_percent' => 50, 'loyalty.point_value' => '0.10', 'loyalty.tiers' => [['key' => 'Gold', 'min_points' => 1000]]])
        ->call('save')->assertHasNoErrors();

    $tenant->refresh();
    expect($tenant->setting('booking.slot_interval'))->toBe(30)
        ->and($tenant->setting('booking.no_show_fee_percent'))->toBe(50)
        ->and($tenant->setting('loyalty.point_value'))->toBe(10)
        ->and($tenant->setting('loyalty.tiers'))->toBe([['key' => 'gold', 'min_points' => 1000]]);

    app(UpdateBusinessSettings::class)->handle($tenant, ['late_cancel_fee_percent' => 500, 'bogus' => 1]);
    expect($tenant->fresh()->setting('booking.late_cancel_fee_percent'))->toBe(100)->and($tenant->fresh()->setting('booking.bogus'))->toBeNull();
});

it('adds a custom domain, shows its DNS records and checks it on demand', function () {
    Queue::fake();
    $tenant = createTenant();
    ws_owner_session($this, $tenant);

    Livewire::test(Domains::class)
        ->callAction('addDomain', data: ['domain' => 'https://Book.MaisonNoor.qa/'])
        ->assertHasNoActionErrors()
        ->assertSee('book.maisonnoor.qa')
        ->assertSee('_luxe.book.maisonnoor.qa');

    $domain = $tenant->domains()->where('type', 'custom')->firstOrFail();
    Queue::assertPushed(VerifyCustomDomain::class);

    $dns = Mockery::mock(DnsResolver::class);
    $dns->shouldReceive('txt', 'cname', 'a')->andReturn([]);
    app()->instance(DnsResolver::class, $dns);

    Livewire::test(Domains::class)->call('checkNow', $domain->id);
    expect($domain->fresh()->last_checked_at)->not->toBeNull()->and($domain->fresh()->verified_at)->toBeNull();
});

it('shows support access sessions in the security log', function () {
    $tenant = createTenant();
    ImpersonationLog::create(['admin_user_id' => platformAdmin()->id, 'tenant_id' => $tenant->id, 'impersonated_user_id' => $tenant->owner_user_id,
        'reason' => 'Helping with booking setup', 'started_at' => now()->subHour(), 'ended_at' => now()->subMinutes(40)]);

    $this->actingAs($tenant->owner)->get(tenantUrl($tenant, 'dashboard/security-log'))
        ->assertOk()->assertSee('Helping with booking setup');
});
