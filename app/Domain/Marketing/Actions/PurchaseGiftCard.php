<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Models\GiftCardOrder;
use App\Domain\Payments\Actions\CreateOnlinePayment;
use App\Domain\Payments\Actions\IssueGiftCard;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\TenantPaymentGateway;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Online gift card purchase: issues a pending GiftCard, a GiftCardOrder (Payable) and a hosted payment.
 *
 * @param  array{amount:int, design:string, purchaser_name:string, purchaser_phone?:?string, purchaser_email?:?string,
 *               recipient_name:string, recipient_phone?:?string, recipient_email?:?string, message?:?string, purchaser_client_id?:?int}  $data
 * @return array{0: GiftCardOrder, 1: Payment}
 */
class PurchaseGiftCard
{
    public const MIN_AMOUNT = 5000;      // QAR 50

    public const MAX_AMOUNT = 1000000;   // QAR 10,000

    public function __construct(protected IssueGiftCard $issue, protected CreateOnlinePayment $payments) {}

    public function handle(array $data, ?string $returnUrl = null): array
    {
        $amount = (int) $data['amount'];
        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            throw new DomainException(__('marketing.gift_cards.errors.amount', ['min' => money(self::MIN_AMOUNT), 'max' => money(self::MAX_AMOUNT)]));
        }
        $gateway = TenantPaymentGateway::for('shop')->first(fn (TenantPaymentGateway $g) => $g->isOnline());
        if (! $gateway) {
            throw new DomainException(__('marketing.errors.no_online_gateway'));
        }

        return DB::transaction(function () use ($data, $amount, $gateway, $returnUrl) {
            $design = in_array($data['design'] ?? null, GiftCardOrder::DESIGNS, true) ? $data['design'] : 'champagne';
            $card = $this->issue->handle($amount, [
                'name' => $data['recipient_name'], 'phone' => $data['recipient_phone'] ?? null,
                'email' => $data['recipient_email'] ?? null, 'message' => $data['message'] ?? null,
            ], $data['purchaser_client_id'] ?? null, $design, paid: false);

            $order = GiftCardOrder::create([
                'gift_card_id' => $card->id, 'purchaser_client_id' => $data['purchaser_client_id'] ?? null,
                'purchaser_name' => $data['purchaser_name'], 'purchaser_phone' => $data['purchaser_phone'] ?? null, 'purchaser_email' => $data['purchaser_email'] ?? null,
                'amount' => $amount, 'currency' => $card->currency, 'design' => $design,
            ]);

            $payment = $this->payments->handle($order, $gateway, $amount, $returnUrl ?? url('/gift-cards/thank-you/'.$order->uuid),
                ['name' => $data['purchaser_name'], 'phone' => $data['purchaser_phone'] ?? null, 'email' => $data['purchaser_email'] ?? null],
                'payment', null, $data['purchaser_client_id'] ?? null);

            return [$order, $payment];
        });
    }
}
