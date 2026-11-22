<?php

namespace App\Domain\Marketing\Models;

use App\Domain\Marketing\Actions\DeliverGiftCard;
use App\Domain\Payments\Actions\IssueGiftCard;
use App\Domain\Payments\Contracts\Payable;
use App\Domain\Payments\Models\GiftCard;
use App\Domain\Payments\Models\Payment;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Online gift card purchase. Wraps the core GiftCard (status "pending" until paid) and implements Payable:
 * success → card activated → delivered to the recipient by WhatsApp / email with a signed card page.
 */
class GiftCardOrder extends Model implements Payable
{
    use BelongsToTenant;

    public const DESIGNS = ['champagne', 'noir', 'rose'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime', 'delivery' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $o) => $o->uuid ??= (string) Str::uuid());
    }

    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class);
    }

    public function paymentDescription(): string
    {
        return __('marketing.gift_cards.payment_description', ['amount' => money($this->amount, $this->currency)]);
    }

    public function onPaymentSucceeded(Payment $payment): void
    {
        if ($this->status === 'paid') {
            return;
        }
        $this->update(['status' => 'paid']);
        $card = GiftCard::withoutGlobalScopes()->find($this->gift_card_id);
        if ($card) {
            app(IssueGiftCard::class)->activate($card);
            $order = $this;
            DB::afterCommit(fn () => app(DeliverGiftCard::class)->handle($order->fresh()));
        }
    }

    public function onPaymentFailed(Payment $payment): void
    {
        if ($this->status === 'pending') {
            $this->update(['status' => 'failed']);
            GiftCard::withoutGlobalScopes()->whereKey($this->gift_card_id)->where('status', 'pending')->update(['status' => 'void']);
        }
    }
}
