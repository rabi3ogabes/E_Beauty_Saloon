<?php

namespace App\Http\Controllers\Marketing;

use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Actions\PurchaseGiftCard;
use App\Domain\Marketing\Models\GiftCardOrder;
use App\Domain\Payments\Actions\SettlePayment;
use App\Domain\Payments\Gateways\GatewayManager;
use App\Domain\Payments\Models\GiftCard;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\TenantPaymentGateway;
use App\Http\Controllers\Controller;
use App\Support\Money\Money;
use Illuminate\Http\Request;

class GiftCardController extends Controller
{
    public const PRESETS = [20000, 50000, 100000, 200000];

    public function index(Request $request)
    {
        return view('marketing.site.gift-cards', [
            'presets' => self::PRESETS,
            'designs' => GiftCardOrder::DESIGNS,
            'client' => $request->user('client'),
            'canPay' => TenantPaymentGateway::for('shop')->contains(fn ($g) => $g->isOnline()),
        ]);
    }

    public function store(Request $request, PurchaseGiftCard $purchase)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:'.(PurchaseGiftCard::MIN_AMOUNT / 100), 'max:'.(PurchaseGiftCard::MAX_AMOUNT / 100)],
            'design' => ['required', 'in:'.implode(',', GiftCardOrder::DESIGNS)],
            'purchaser_name' => ['required', 'string', 'max:100'],
            'purchaser_phone' => ['nullable', 'string', 'max:20'],
            'purchaser_email' => ['nullable', 'email', 'max:150'],
            'recipient_name' => ['required', 'string', 'max:100'],
            'recipient_phone' => ['nullable', 'required_without:recipient_email', 'string', 'max:20'],
            'recipient_email' => ['nullable', 'required_without:recipient_phone', 'email', 'max:150'],
            'message' => ['nullable', 'string', 'max:300'],
        ]);
        $data['amount'] = Money::fromDecimal((string) $data['amount'], tenant()->currency ?? 'QAR')->amount;
        $data['recipient_phone'] = filled($data['recipient_phone'] ?? null) ? Client::normalizePhone($data['recipient_phone']) : null;
        $data['purchaser_phone'] = filled($data['purchaser_phone'] ?? null) ? Client::normalizePhone($data['purchaser_phone']) : null;
        $data['purchaser_client_id'] = $request->user('client')?->id;

        [$order, $payment] = $purchase->handle($data);
        $payment->update(['meta' => array_merge($payment->meta ?? [], ['return_to' => url('/gift-cards/thank-you/'.$order->uuid)])]);

        return redirect()->away($payment->checkout_url ?? url('/gift-cards/thank-you/'.$order->uuid));
    }

    /** Landing after the hosted page: re-verify with the provider (never trust the redirect). */
    public function thankYou(string $uuid, GatewayManager $gateways, SettlePayment $settle)
    {
        $order = GiftCardOrder::where('uuid', $uuid)->firstOrFail();
        $payment = Payment::where('payable_type', $order->getMorphClass())->where('payable_id', $order->id)->latest('id')->first();
        if ($payment && $payment->status === 'pending' && $payment->gateway && $payment->provider_reference) {
            rescue(function () use ($gateways, $payment, $settle) {
                $status = $gateways->forTenantGateway($payment->gateway)->status($payment->provider_reference);
                $status === 'succeeded' ? $settle->succeed($payment) : ($status === 'failed' ? $settle->fail($payment, 'provider_status') : null);
            }, report: false);
            $order->refresh();
        }

        return view('marketing.site.gift-card-thanks', ['order' => $order, 'card' => GiftCard::find($order->gift_card_id)]);
    }

    public function show(string $code)
    {
        $card = GiftCard::where('code', strtoupper($code))->whereIn('status', ['active', 'redeemed'])->firstOrFail();

        return view('marketing.site.gift-card', ['card' => $card]);
    }
}
