<?php

namespace App\Domain\Payments\Actions;

use App\Domain\CRM\Models\Client;
use App\Domain\Loyalty\Actions\LoyaltyLedger;
use App\Domain\Payments\Gateways\GatewayManager;
use App\Domain\Payments\Models\GiftCard;
use App\Domain\Payments\Models\Payment;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Refunds back to the original method (gateway / wallet / gift card / points / cash),
 * or as store credit to the client's wallet.
 */
class RefundPayment
{
    public function __construct(protected GatewayManager $gateways, protected WalletLedger $wallet, protected GiftCardLedger $giftCards, protected LoyaltyLedger $loyalty) {}

    public function handle(Payment $payment, int $amount, bool $asStoreCredit = false, ?string $reason = null): Payment
    {
        if ($amount <= 0 || $amount > $payment->refundable()) {
            throw new DomainException(__('salon.errors.refund_amount'));
        }

        return DB::transaction(function () use ($payment, $amount, $asStoreCredit, $reason) {
            $payment = Payment::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);
            $method = $asStoreCredit ? 'wallet' : $payment->method;

            if ($asStoreCredit) {
                $client = Client::withoutGlobalScopes()->findOrFail($payment->client_id ?? throw new DomainException(__('salon.errors.refund_needs_client')));
                $this->wallet->credit($client, $amount, 'store_credit', $payment, "refund:{$payment->id}:".($payment->refunded_amount + $amount));
            } else {
                match ($payment->method) {
                    'online' => $payment->gateway && ! $this->gateways->forTenantGateway($payment->gateway)->refund((string) $payment->provider_reference, $amount, $payment->currency)
                        ? throw new DomainException(__('salon.errors.gateway_refund_failed')) : null,
                    'wallet' => $this->wallet->credit(Client::withoutGlobalScopes()->findOrFail($payment->client_id), $amount, 'refund', $payment),
                    'gift_card' => $this->giftCards->refund(GiftCard::withoutGlobalScopes()->findOrFail($payment->meta['gift_card_id']), $amount, $payment),
                    'points' => $this->loyalty->award(Client::withoutGlobalScopes()->findOrFail($payment->client_id), (int) ($payment->meta['points'] ?? 0), 'reversal', $payment),
                    default => null, // cash / card terminal handled at the counter
                };
            }

            $refund = Payment::withoutGlobalScopes()->create([
                'tenant_id' => $payment->tenant_id,
                'branch_id' => $payment->branch_id,
                'client_id' => $payment->client_id,
                'tenant_payment_gateway_id' => $payment->tenant_payment_gateway_id,
                'payable_type' => $payment->payable_type,
                'payable_id' => $payment->payable_id,
                'method' => $method,
                'driver' => $payment->driver,
                'purpose' => 'refund',
                'status' => 'succeeded',
                'amount' => -$amount,
                'currency' => $payment->currency,
                'parent_payment_id' => $payment->id,
                'paid_at' => now(),
                'meta' => ['reason' => $reason],
            ]);

            $payment->refunded_amount += $amount;
            $payment->status = $payment->refunded_amount >= $payment->amount ? 'refunded' : 'partially_refunded';
            $payment->save();

            activity()->performedOn($payment)->withProperties(['amount' => $amount, 'reason' => $reason, 'store_credit' => $asStoreCredit])->log('payment.refunded');

            return $refund;
        });
    }
}
