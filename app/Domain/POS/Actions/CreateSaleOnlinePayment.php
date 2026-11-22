<?php

namespace App\Domain\POS\Actions;

use App\Domain\CRM\Models\Client;
use App\Domain\Payments\Actions\CreateOnlinePayment;
use App\Domain\Payments\Actions\SettlePayment;
use App\Domain\Payments\Gateways\GatewayManager;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\TenantPaymentGateway;
use App\Domain\POS\Enums\SaleStatus;
use App\Domain\POS\Models\Sale;
use App\Support\Exceptions\DomainException;

/**
 * "Online link / QR" tender (SADAD, SkipCash...): parks the ticket as a draft sale (same idempotency
 * key the terminal will complete with) and opens a hosted checkout. The terminal polls status().
 */
class CreateSaleOnlinePayment
{
    public function __construct(protected CreateOnlinePayment $create, protected SettlePayment $settle, protected GatewayManager $gateways) {}

    public function handle(string $idempotencyKey, int $branchId, int $amount, ?int $clientId = null): Payment
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));
        if ($amount <= 0) {
            throw new DomainException(__('pos.errors.amount_required'));
        }
        $gateway = TenantPaymentGateway::query()->for('pos', $branchId)->first(fn (TenantPaymentGateway $g) => $g->isOnline())
            ?? throw new DomainException(__('pos.errors.no_online_gateway'));

        $sale = Sale::withoutGlobalScopes()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            ['tenant_id' => tenant()->id, 'branch_id' => $branchId, 'client_id' => $clientId, 'status' => SaleStatus::Draft, 'currency' => tenant()->currency ?? 'QAR', 'user_id' => auth()->id()],
        );
        if ($sale->status !== SaleStatus::Draft) {
            throw new DomainException(__('pos.errors.already_completed'));
        }
        $client = $clientId ? Client::find($clientId) : null;

        return $this->create->handle(
            $sale, $gateway, $amount,
            returnUrl: url('/pos'),
            customer: array_filter(['name' => $client?->name, 'phone' => $client?->phone, 'email' => $client?->email]),
            purpose: 'payment', branchId: $branchId, clientId: $clientId,
        );
    }

    /** Current status of a POS online payment, asking the gateway when the webhook has not arrived yet. */
    public function status(Payment $payment): string
    {
        if ($payment->status === 'pending' && $payment->gateway && $payment->provider_reference) {
            try {
                $remote = $this->gateways->forTenantGateway($payment->gateway)->status((string) $payment->provider_reference);
                if ($remote === 'succeeded') {
                    $payment = $this->settle->succeed($payment);
                } elseif ($remote === 'failed') {
                    $payment = $this->settle->fail($payment, 'gateway');
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $payment->status;
    }
}
