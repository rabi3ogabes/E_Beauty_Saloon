<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Contracts\Payable;
use App\Domain\Payments\Gateways\GatewayManager;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\TenantPaymentGateway;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Creates a pending Payment and a hosted checkout session on the salon's gateway.
 * Returns the payment with checkout_url set — redirect the client there.
 */
class CreateOnlinePayment
{
    public function __construct(protected GatewayManager $gateways) {}

    public function handle(Model&Payable $payable, TenantPaymentGateway $gateway, int $amount, string $returnUrl, array $customer = [], string $purpose = 'payment', ?int $branchId = null, ?int $clientId = null): Payment
    {
        $payment = Payment::create([
            'branch_id' => $branchId,
            'client_id' => $clientId,
            'tenant_payment_gateway_id' => $gateway->id,
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'method' => 'online',
            'driver' => $gateway->driver,
            'purpose' => $purpose,
            'status' => 'pending',
            'amount' => $amount,
            'currency' => tenant()?->currency ?? 'QAR',
            'idempotency_key' => 'pay_'.Str::ulid(),
        ]);

        $session = $this->gateways->forTenantGateway($gateway)->createCheckout([
            'amount' => $amount,
            'currency' => $payment->currency,
            'reference' => $payment->idempotency_key,
            'description' => $payable->paymentDescription(),
            'customer' => $customer,
            'return_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            'webhook_url' => $gateway->webhookUrl(),
        ]);

        $payment->update(['provider_reference' => $session->reference, 'checkout_url' => $session->redirectUrl, 'meta' => ['qr' => $session->qrPayload]]);

        return $payment;
    }
}
