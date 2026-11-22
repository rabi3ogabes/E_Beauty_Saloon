<?php

require_once __DIR__.'/WorkspaceHelpers.php';

use App\Domain\Payments\Models\TenantPaymentGateway;
use App\Domain\Workspace\Actions\SavePaymentGateway;
use App\Domain\Workspace\Actions\TestGatewayConnection;
use App\Filament\Dashboard\Resources\PaymentGateways\Pages\CreatePaymentGateway;
use App\Filament\Dashboard\Resources\PaymentGateways\Pages\EditPaymentGateway;
use App\Filament\Dashboard\Resources\PaymentGateways\Pages\ListPaymentGateways;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('gateway credentials are encrypted at rest and never shown back in plain text', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);

    Livewire::test(CreatePaymentGateway::class)
        ->fillForm(['driver' => 'sadad', 'mode' => 'test', 'is_active' => true, 'enabled_for' => ['booking', 'deposits'],
            'credentials' => ['merchant_id' => '7654321', 'secret_key' => 'sadad-very-secret-key', 'website' => 'mysalon.qa']])
        ->call('create')->assertHasNoFormErrors();

    $gateway = TenantPaymentGateway::firstOrFail();
    $raw = DB::table('tenant_payment_gateways')->where('id', $gateway->id)->value('credentials');
    expect($raw)->not->toContain('sadad-very-secret-key')->not->toContain('7654321')
        ->and($gateway->credential('secret_key'))->toBe('sadad-very-secret-key')
        ->and($gateway->enabled_for)->toBe(['booking', 'deposits']);

    $edit = Livewire::test(EditPaymentGateway::class, ['record' => $gateway->id]);
    expect(json_encode($edit->get('data')))->not->toContain('sadad-very-secret-key');
    $edit->assertDontSeeHtml('sadad-very-secret-key')->assertSeeHtml(SavePaymentGateway::mask('sadad-very-secret-key'));

    // Blank inputs keep the stored secret; a filled one replaces it.
    $edit->fillForm(['credentials' => ['secret_key' => '', 'website' => 'new.qa']])->call('save')->assertHasNoFormErrors();
    expect($gateway->fresh()->credential('secret_key'))->toBe('sadad-very-secret-key')->and($gateway->fresh()->credential('website'))->toBe('new.qa');
});

it('test-connection works for SADAD', function () {
    Http::fake(['sadadqa.com/*' => Http::response('ok', 200)]);
    $tenant = createTenant();
    ws_owner_session($this, $tenant);
    $gateway = app(SavePaymentGateway::class)->handle(null, ['driver' => 'sadad', 'enabled_for' => ['booking'], 'credentials' => ['merchant_id' => '1', 'secret_key' => 'k', 'website' => 'x.qa']]);

    Livewire::test(ListPaymentGateways::class)->callTableAction('testConnection', $gateway)->assertHasNoTableActionErrors();

    expect($gateway->fresh()->last_test_ok)->toBeTrue()->and($gateway->fresh()->last_tested_at)->not->toBeNull();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'sadadqa.com'));
});

it('test-connection works for Stripe and records failures', function () {
    Http::fake([
        'api.stripe.com/v1/balance' => Http::sequence()
            ->push(['object' => 'balance', 'available' => []], 200)
            ->push(['error' => ['message' => 'Invalid API Key provided']], 401),
    ]);
    $tenant = createTenant();
    asTenant($tenant);
    $gateway = app(SavePaymentGateway::class)->handle(null, ['driver' => 'stripe', 'enabled_for' => ['shop'], 'credentials' => ['secret_key' => 'sk_test_123', 'webhook_secret' => 'whsec_1']]);

    [$ok] = app(TestGatewayConnection::class)->handle($gateway);
    expect($ok)->toBeTrue()->and($gateway->fresh()->last_test_ok)->toBeTrue();

    [$ok, $message] = app(TestGatewayConnection::class)->handle($gateway);
    expect($ok)->toBeFalse()->and($message)->toBe('Invalid API Key provided')->and($gateway->fresh()->last_test_ok)->toBeFalse();
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk_test_123'));
});

it('resets the test status when credentials change and exposes the webhook URL', function () {
    $tenant = createTenant();
    asTenant($tenant);
    $gateway = app(SavePaymentGateway::class)->handle(null, ['driver' => 'stripe', 'credentials' => ['secret_key' => 'sk_1']]);
    $gateway->forceFill(['last_test_ok' => true, 'last_tested_at' => now()])->save();

    app(SavePaymentGateway::class)->handle($gateway, ['driver' => 'stripe', 'credentials' => ['secret_key' => 'sk_2']]);
    expect($gateway->fresh()->last_tested_at)->toBeNull()
        ->and($gateway->webhookUrl())->toContain('/webhooks/gateways/'.$gateway->id.'/');
});
