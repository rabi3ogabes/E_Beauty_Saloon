<?php

use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\PlatformPayment;
use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Models\TenantChannelSetting;
use App\Support\Exceptions\DomainException;
use App\Support\Http\SafeUrl;
use Illuminate\Support\Facades\Http;

it('refuses to settle a platform invoice through the fake driver or an unconfigured gateway', function () {
    $tenant = createTenant();
    $invoice = app(IssueInvoice::class)->create($tenant, [['type' => 'credits', 'description' => 'x', 'quantity' => 1, 'unit_amount' => 10000, 'amount' => 10000]]);
    PlatformPayment::create(['tenant_id' => $tenant->id, 'invoice_id' => $invoice->id, 'gateway' => 'stripe', 'status' => 'pending', 'amount' => 10000, 'idempotency_key' => 'PLT-1-ABC']);

    $body = ['merchant_reference' => 'PLT-1-ABC', 'status' => 'succeeded', 'event_id' => 'evt1'];
    $this->postJson(centralUrl('webhooks/platform/fake'), $body)->assertNotFound();
    $this->postJson(centralUrl('webhooks/platform/stripe'), $body)->assertNotFound(); // not configured

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Open);
});

it('requires a valid signature and provider confirmation for platform webhooks', function () {
    config(['services.platform_gateways.stripe' => ['secret_key' => 'sk_test', 'webhook_secret' => 'whsec']]);
    $tenant = createTenant();
    $invoice = app(IssueInvoice::class)->create($tenant, [['type' => 'credits', 'description' => 'x', 'quantity' => 1, 'unit_amount' => 10000, 'amount' => 10000]]);
    PlatformPayment::create(['tenant_id' => $tenant->id, 'invoice_id' => $invoice->id, 'gateway' => 'stripe', 'status' => 'pending', 'amount' => 10000, 'idempotency_key' => 'PLT-1-ABC', 'reference' => 'cs_1']);

    $event = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'client_reference_id' => 'PLT-1-ABC', 'amount_total' => 10000]]]);
    $sign = fn ($payload) => 't='.time().',v1='.hash_hmac('sha256', time().'.'.$payload, 'whsec');

    // Bad signature
    $this->call('POST', centralUrl('webhooks/platform/stripe'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=bad', 'CONTENT_TYPE' => 'application/json'], $event)->assertStatus(400);

    // Valid signature but provider says unpaid
    Http::fake(['api.stripe.com/*' => Http::sequence()->push(['payment_status' => 'unpaid', 'status' => 'open'])->push(['payment_status' => 'paid', 'status' => 'complete'])]);
    $this->call('POST', centralUrl('webhooks/platform/stripe'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sign($event), 'CONTENT_TYPE' => 'application/json'], $event)->assertStatus(422);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Open);

    // Provider confirms → paid
    $event2 = str_replace('evt_1', 'evt_2', $event);
    $this->call('POST', centralUrl('webhooks/platform/stripe'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sign($event2), 'CONTENT_TYPE' => 'application/json'], $event2)->assertOk();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('rejects unsafe outbound URLs (SSRF guard)', function () {
    foreach (['http://example.com/sms', 'https://127.0.0.1/x', 'https://169.254.169.254/latest/meta-data', 'https://10.0.0.5/', 'https://user:pass@8.8.8.8/'] as $url) {
        expect(fn () => SafeUrl::assert($url))->toThrow(DomainException::class);
    }
    expect(SafeUrl::assert('https://8.8.8.8/send'))->toBe('https://8.8.8.8/send');
});

it('only lets a salon-signed WhatsApp webhook touch that salon', function () {
    $a = createTenant(slug: 'alpha');
    $b = createTenant(slug: 'beta');
    asTenant($a, fn () => TenantChannelSetting::create(['channel' => 'whatsapp', 'credentials' => ['phone_number_id' => 'PN-A', 'app_secret' => 'secret-a', 'token' => 't'], 'is_active' => true]));
    asTenant($b, fn () => TenantChannelSetting::create(['channel' => 'whatsapp', 'credentials' => ['phone_number_id' => 'PN-B', 'app_secret' => 'secret-b', 'token' => 't'], 'is_active' => true]));
    $clientB = asTenant($b, fn () => Client::create(['name' => 'B', 'phone' => '+97455559999']));
    asTenant($b, fn () => $clientB->setConsent('whatsapp', true));

    // Tenant A signs a payload that targets B's number (plus one of its own) and sends STOP.
    $payload = json_encode(['entry' => [['changes' => [
        ['value' => ['metadata' => ['phone_number_id' => 'PN-A'], 'messages' => []]],
        ['value' => ['metadata' => ['phone_number_id' => 'PN-B'], 'messages' => [['from' => '97455559999', 'id' => 'm1', 'type' => 'text', 'text' => ['body' => 'STOP']]]]],
    ]]]]);
    $sig = 'sha256='.hash_hmac('sha256', $payload, 'secret-a');
    $this->call('POST', centralUrl('webhooks/whatsapp'), [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload);

    expect(asTenant($b, fn () => $clientB->fresh()->hasConsent('whatsapp')))->toBeTrue();

    // A salon cannot claim another salon's WhatsApp number.
    expect(fn () => asTenant($a, fn () => TenantChannelSetting::create(['channel' => 'whatsapp', 'credentials' => ['phone_number_id' => 'PN-B'], 'is_active' => true])))
        ->toThrow(DomainException::class);
});
